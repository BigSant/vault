<?php

namespace App\Vault;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;

final class AccountRecovery
{
    public function reset(string $email, #[\SensitiveParameter] string $password): void
    {
        abort_unless(strlen($password) >= 24 && strlen($password) <= 200, 422);
        DB::transaction(function () use ($email, $password) {
            $user = User::where('email', strtolower($email))->lockForUpdate()->firstOrFail();
            app(Secrets::class)->resetMfa($user->id, TOTP::generate()->getSecret());
            $user->update(['password' => Hash::make($password), 'mfa_enabled' => false, 'totp_counter' => -1]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            app(Access::class)->audit(null, 'user.recover', $user->id, 'ok', 'operator');
        });
    }
}
