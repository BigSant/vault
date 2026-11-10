<?php

namespace App\Http;

use App\Models\User;
use App\Vault\Access;
use App\Vault\Secrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OTPHP\TOTP;

final class Identity
{
    public function __construct(private Secrets $secrets, private Access $access) {}

    public function loginForm()
    {
        return view('login', ['setup' => DB::table('settings')->where('key', 'bootstrap_used')->value('value') === '0']);
    }

    public function setup(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:200', 'password' => 'required|string|min:14|max:200', 'bootstrap_code' => 'required|string|max:100']);
        $path = config('vault.bootstrap_file');
        abort_unless(is_file($path) && hash_equals(trim(file_get_contents($path)), $d['bootstrap_code']), 403);
        $user = DB::transaction(function () use ($d) {
            $used = DB::table('settings')->where('key', 'bootstrap_used')->lockForUpdate()->first();
            abort_unless($used->value === '0', 409);
            $id = (string) Str::uuid();
            $totp = TOTP::generate();
            $this->secrets->write('auth/'.$id, ['totp' => $totp->getSecret()], 0);
            $user = User::create(['id' => $id, 'name' => $d['name'], 'email' => strtolower($d['email']), 'password' => Hash::make($d['password'])]);
            $ws = (string) Str::uuid();
            DB::table('workspaces')->insert(['id' => $ws, 'name' => 'Asmeniniai', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('members')->insert(['workspace_id' => $ws, 'user_id' => $id, 'role' => 'owner']);
            DB::table('settings')->where('key', 'bootstrap_used')->update(['value' => '1']);
            $this->access->audit($ws, 'owner.setup', $id, 'ok', 'user:'.$id);

            return $user;
        });
        $r->session()->regenerate();
        $r->session()->put(['pending_user' => $user->id, 'pending_at' => time()]);

        return redirect('/mfa');
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:200', 'password' => 'required|string|max:200']);
        $user = User::where('email', strtolower($d['email']))->first();
        // Same expensive verification for unknown accounts.
        $dummy = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$AAoTpTn3Eg6iAmTh5Is/ogPzwTxYeZpKoThomKwtmjQ';
        $valid = password_verify($d['password'], $user?->password ?? $dummy);
        abort_unless($valid && $user, 401);
        $r->session()->regenerate();
        $r->session()->put(['pending_user' => $user->id, 'pending_at' => time()]);

        return redirect('/mfa');
    }

    private function pending(Request $r): User
    {
        abort_unless(time() - (int) $r->session()->get('pending_at', 0) < 300, 401);

        return User::findOrFail($r->session()->get('pending_user'));
    }

    public function mfaForm(Request $r)
    {
        $user = $this->pending($r);
        $seed = $user->mfa_enabled ? null : $this->secrets->read('auth/'.$user->id)['totp'];

        return view('mfa', ['seed' => $seed]);
    }

    private function verify(User $user, #[\SensitiveParameter] string $code): void
    {
        $seed = $this->secrets->read('auth/'.$user->id)['totp'];
        $totp = TOTP::createFromSecret($seed);
        $counter = intdiv(time(), 30);
        abort_unless(preg_match('/^\d{6}$/D', $code) && $totp->verify($code, null, 0), 401);
        $changed = DB::table('users')->where('id', $user->id)->where('totp_counter', '<', $counter)->update(['totp_counter' => $counter, 'mfa_enabled' => true]);
        abort_unless($changed === 1, 401);
    }

    public function mfa(Request $r)
    {
        $d = $r->validate(['code' => 'required|string|size:6']);
        $user = $this->pending($r);
        $this->verify($user, $d['code']);
        Auth::login($user);
        $r->session()->regenerate();
        $r->session()->forget(['pending_user', 'pending_at']);
        $r->session()->put(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time()]);
        $this->access->audit(null, 'session.login', null);

        return redirect('/');
    }

    public function confirm(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|string|max:200']);
        abort_unless(Hash::check($d['current_password'], $r->user()->password), 401);
        $r->session()->put('confirmed_at', time());

        return redirect('/');
    }

    public function changePassword(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|string|max:200', 'password' => 'required|string|min:14|max:200|confirmed']);
        abort_unless(Hash::check($d['current_password'], $r->user()->password), 401);
        DB::transaction(function () use ($r, $d) {
            DB::table('users')->where('id', $r->user()->id)->update(['password' => Hash::make($d['password'])]);
            DB::table('sessions')->where('user_id', $r->user()->id)->delete();
            $this->access->audit(null, 'user.password', $r->user()->id);
        });

        return $this->logout($r);
    }

    public function logout(Request $r)
    {
        $this->access->audit(null, 'session.logout');
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
