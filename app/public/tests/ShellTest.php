<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/** The signed-in shell: header navigation, the account page and the password confirmation flow. */
final class ShellTest extends TestCase
{
    private const PASSWORD = 'Synthetic-password-123!';

    private string $workspace;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->workspace = (string) Str::uuid();
        $this->base = '/workspaces/'.$this->workspace;
        DB::table('workspaces')->insert(['id' => $this->workspace, 'name' => 'Main space']);
    }

    private function signIn(string $role, ?int $confirmedAt = null): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'name' => ucfirst($role).' Person', 'email' => $role.'@example.test', 'password' => Hash::make(self::PASSWORD), 'mfa_enabled' => true]);
        DB::table('members')->insert(['workspace_id' => $this->workspace, 'user_id' => $user->id, 'role' => $role]);
        $this->actingAs($user)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => $confirmedAt ?? time(), 'auth_revision' => $user->credentialRevision()]);

        return $user;
    }

    private function waitingRequest(string $state = 'pending', string $workspace = ''): void
    {
        $machine = (string) Str::uuid();
        DB::table('machines')->insert(['id' => $machine, 'workspace_id' => $workspace ?: $this->workspace, 'name' => 'agent', 'token_hash' => hash('sha256', $machine), 'expires_at' => now()->addDay()]);
        DB::table('operations')->insert(['id' => (string) Str::uuid(), 'machine_id' => $machine, 'grant_id' => (string) Str::uuid(), 'entry_id' => (string) Str::uuid(),
            'action' => 'test.http', 'version' => 1, 'state' => $state, 'request_id' => 'r-'.$machine, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function roles(): array
    {
        return [
            'owner' => ['owner', ['requests', 'members', 'structure', 'audit'], []],
            'editor' => ['editor', ['structure'], ['requests', 'members', 'audit']],
            'viewer' => ['viewer', [], ['requests', 'members', 'structure', 'audit']],
        ];
    }

    #[DataProvider('roles')]
    public function test_primary_nav_shows_only_what_the_role_can_open(string $role, array $shown, array $hidden): void
    {
        $this->signIn($role);
        $response = $this->get('/')->assertOk()->assertSee('aria-label="Main"', false)->assertSee('href="/?workspace='.$this->workspace.'"', false)
            ->assertSee('href="/account"', false)->assertSee('action="/logout"', false)->assertSee('href="/workspaces/new"', false)
            ->assertDontSee('Confirm sensitive actions')->assertDontSee('Change sign-in password');
        foreach ($shown as $section) {
            $response->assertSee('href="'.$this->base.'/'.$section.'"', false);
        }
        foreach ($hidden as $section) {
            $response->assertDontSee('href="'.$this->base.'/'.$section.'"', false);
        }
    }

    public function test_active_nav_item_and_setup_tabs_follow_the_page(): void
    {
        $this->signIn('editor');
        $this->get($this->base.'/structure')->assertOk()
            ->assertSee('<a class="btn on" href="'.$this->base.'/structure" aria-current="page">Setup</a>', false)
            ->assertSeeInOrder(['aria-label="Setup sections"', 'href="'.$this->base.'/structure"', 'class="on" aria-current="page"', 'Projects', 'href="'.$this->base.'/types"', 'Entry types'], false);
        $this->get($this->base.'/types')->assertOk()
            ->assertSee('<a class="btn on" href="'.$this->base.'/structure" aria-current="page">Setup</a>', false)
            ->assertSeeInOrder(['aria-label="Setup sections"', 'Projects', 'href="'.$this->base.'/types"', 'class="on" aria-current="page"', 'Entry types'], false);
        $this->get('/')->assertOk()->assertSee('<a class="btn on" href="/?workspace='.$this->workspace.'" aria-current="page">Entries</a>', false);
    }

    public function test_agents_item_counts_waiting_requests_for_owners_only(): void
    {
        $other = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $other, 'name' => 'Other space']);
        $this->waitingRequest('pending');
        $this->waitingRequest('requires_user_action');
        $this->waitingRequest('succeeded');
        $this->waitingRequest('pending', $other);
        $chip = '<span class="chip warn" title="Requests waiting for you">2<span class="sr-only"> requests waiting</span></span>';
        $this->signIn('owner');
        $this->get('/')->assertOk()->assertSee($chip, false);
        $this->signIn('editor');
        $this->get('/')->assertOk()->assertDontSee('Requests waiting for you');
    }

    public function test_workspace_switcher_keeps_the_section_when_the_role_allows_it(): void
    {
        $user = $this->signIn('owner');
        $second = (string) Str::uuid();
        $third = (string) Str::uuid();
        DB::table('workspaces')->insert([['id' => $second, 'name' => 'Second space'], ['id' => $third, 'name' => 'Third space']]);
        DB::table('members')->insert([['workspace_id' => $second, 'user_id' => $user->id, 'role' => 'editor'], ['workspace_id' => $third, 'user_id' => $user->id, 'role' => 'viewer']]);
        $this->get($this->base.'/structure')->assertOk()->assertSee('Second space')
            ->assertSee('href="/workspaces/'.$second.'/structure"', false)->assertSee('href="/?workspace='.$third.'"', false);
        $this->get($this->base.'/audit')->assertOk()->assertSee('href="/?workspace='.$second.'"', false)->assertDontSee('/workspaces/'.$second.'/audit');
    }

    public function test_account_page_shows_identity_confirmation_state_and_forms(): void
    {
        $this->signIn('viewer');
        $this->get('/account')->assertOk()->assertSee('Viewer Person')->assertSee('viewer@example.test')->assertSee('Main space')
            ->assertSee('confirmed</span> until', false)->assertSee('action="/password"', false)->assertSee('action="/logout"', false)
            ->assertDontSee('name="return" value="/account"', false);
        $this->withSession(['confirmed_at' => time() - 301])->get('/account')->assertOk()->assertSee('not confirmed')
            ->assertSee('name="return" value="/account"', false);
        $this->get('/workspaces/new')->assertOk()->assertSee('action="/workspaces"', false);
    }

    public function test_unconfirmed_sensitive_post_answers_403_with_the_confirm_form(): void
    {
        $this->signIn('owner', time() - 301);
        $this->get($this->base.'/structure?from=test')->assertOk();
        // Browsers send no Referer here, so the last page shown is the way back.
        $this->post($this->base.'/projects', ['name' => 'shop', 'back' => 'structure'])->assertForbidden()
            ->assertSee('action="/confirm"', false)->assertSee('name="current_password"', false)
            ->assertSee('name="return" value="'.$this->base.'/structure?from=test"', false);
        $this->assertSame(0, DB::table('projects')->count());
        $this->post($this->base.'/projects', ['name' => 'shop'], ['Referer' => rtrim(config('app.url'), '/').$this->base.'/types'])->assertForbidden()
            ->assertSee('name="return" value="'.$this->base.'/types"', false);
        $this->post($this->base.'/projects', ['name' => 'shop'], ['Referer' => 'https://evil.example/workspaces/x'])->assertForbidden()
            ->assertSee('name="return" value="'.$this->base.'/structure?from=test"', false)->assertDontSee('evil.example');
        $this->postJson($this->base.'/projects', ['name' => 'shop'])->assertForbidden()->assertExactJson(['error' => 'access_denied']);
        $this->get('/confirm?return='.urlencode($this->base.'/types'))->assertOk()->assertSee('name="return" value="'.$this->base.'/types"', false);
        $this->get('/confirm?return='.urlencode('//evil.example'))->assertOk()->assertSee('name="return" value="/"', false);
    }

    public function test_confirm_returns_to_a_safe_path_and_then_the_action_works(): void
    {
        $this->signIn('owner', time() - 301);
        $this->post('/confirm', ['current_password' => 'wrong-password', 'return' => $this->base.'/structure'])->assertUnauthorized()
            ->assertSee('name="return" value="'.$this->base.'/structure"', false);
        $this->post($this->base.'/projects', ['name' => 'shop', 'back' => 'structure'])->assertForbidden();
        $this->post('/confirm', ['current_password' => self::PASSWORD, 'return' => $this->base.'/structure?x=1'])->assertRedirect($this->base.'/structure?x=1');
        $this->post($this->base.'/projects', ['name' => 'shop', 'back' => 'structure'])->assertRedirect($this->base.'/structure');
        $this->assertSame(1, DB::table('projects')->count());
    }

    public function test_stale_confirmation_is_refreshed_by_a_correct_confirm_password_in_the_same_post(): void
    {
        $this->signIn('owner', time() - 301);
        $this->get($this->base.'/structure')->assertOk()->assertSessionHas('last_page', $this->base.'/structure');
        $this->post($this->base.'/projects', ['name' => 'shop', 'back' => 'structure', 'confirm_password' => self::PASSWORD])
            ->assertRedirect($this->base.'/structure')->assertSessionMissing('_old_input');
        $this->assertSame(1, DB::table('projects')->count());
        $this->assertGreaterThanOrEqual(time() - 2, session('confirmed_at'));
        // The refreshed confirmation carries the next action without a password.
        $this->post($this->base.'/projects', ['name' => 'blog', 'back' => 'structure'])->assertRedirect($this->base.'/structure');
        $this->assertSame(2, DB::table('projects')->count());
    }

    public function test_wrong_confirm_password_answers_403_with_the_confirm_form_and_is_never_flashed(): void
    {
        $this->signIn('owner', $stale = time() - 301);
        $this->post($this->base.'/projects', ['name' => 'shop', 'back' => 'structure', 'confirm_password' => 'wrong-password'])->assertForbidden()
            ->assertSee('action="/confirm"', false)->assertSee('That password is not right')->assertDontSee('wrong-password');
        $this->assertSame(0, DB::table('projects')->count());
        $this->assertSame($stale, session('confirmed_at'));
        // A query string is no place for a password, even a correct one.
        $this->post($this->base.'/projects?confirm_password='.urlencode(self::PASSWORD), ['name' => 'shop'])->assertForbidden();
        // A validation error flashes the other input back, never the password. The confirmation it refreshed stays.
        $this->post($this->base.'/projects', ['name' => '', 'back' => 'structure', 'confirm_password' => self::PASSWORD])->assertUnprocessable();
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(session()->all()));
        $this->assertStringNotContainsString('confirm_password', json_encode(session()->all()));
    }

    public function test_confirm_password_attempts_are_rate_limited(): void
    {
        $this->signIn('owner', time() - 301);
        for ($i = 0; $i < 10; $i++) {
            $this->post($this->base.'/projects', ['name' => 'shop', 'confirm_password' => 'wrong-password'])->assertForbidden();
        }
        $this->post($this->base.'/projects', ['name' => 'shop', 'confirm_password' => self::PASSWORD])->assertTooManyRequests();
        $this->assertSame(0, DB::table('projects')->count());
    }

    public function test_pages_outside_a_workspace_keep_the_last_workspace_in_the_header(): void
    {
        $user = $this->signIn('owner');
        $second = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $second, 'name' => 'Another space']);
        DB::table('members')->insert(['workspace_id' => $second, 'user_id' => $user->id, 'role' => 'owner']);
        $this->get('/account')->assertOk()->assertSee('aria-label="Workspace: none chosen"', false)->assertDontSee('/members"', false);
        $this->get($this->base.'/members')->assertOk()->assertSessionHas('last_workspace', $this->workspace);
        foreach (['/account', '/workspaces/new'] as $page) {
            $this->get($page)->assertOk()->assertSee('aria-label="Workspace: Main space"', false)
                ->assertSee('href="'.$this->base.'/requests"', false)->assertSee('href="'.$this->base.'/audit"', false)->assertDontSee('class="btn on"', false);
        }
        // Losing the membership drops the remembered workspace from the header.
        DB::table('members')->where('workspace_id', $this->workspace)->delete();
        $this->get('/account')->assertOk()->assertSee('aria-label="Workspace: none chosen"', false)->assertDontSee($this->base.'/requests');
    }

    public function test_shell_has_a_skip_link_live_region_and_sign_out_wording(): void
    {
        $this->signIn('owner');
        $this->get($this->base.'/agents')->assertOk()
            ->assertSeeInOrder(['<body>', '<a class="skip" href="#content">Skip to content</a>', '<header', '<main id="content" tabindex="-1">', 'id="live"'], false)
            ->assertSee('aria-label="Agents sections"', false)->assertSee('Identities')->assertSee('<button>Sign out</button>', false)->assertDontSee('Lock');
        $this->get('/account')->assertOk()->assertSee('Change password and sign out')->assertSee('<span class="chip role">owner</span>', false)->assertDontSee('>Lock<', false);
        // A signed-in person keeps the header on an error page; a guest gets the bare page.
        $this->get('/nothing-here')->assertNotFound()->assertSee('Not found')->assertDontSee('not refilled')
            ->assertSee('aria-label="Main"', false)->assertSee('<button>Sign out</button>', false)->assertSessionHas('last_page', '/account');
        $this->get('/api/nothing-here')->assertNotFound();
        $this->post($this->base.'/projects', ['name' => ''])->assertUnprocessable()->assertSee('Check the form')->assertSee('not refilled');
    }

    public function test_error_pages_stay_bare_for_guests_and_unfinished_sign_ins(): void
    {
        $this->get('/nothing-here')->assertNotFound()->assertSee('Not found')->assertSee('credential store')->assertDontSee('aria-label="Main"', false);
        $user = User::create(['id' => (string) Str::uuid(), 'name' => 'Half Signed', 'email' => 'half@example.test', 'password' => Hash::make(self::PASSWORD), 'mfa_enabled' => true]);
        $this->actingAs($user)->get('/nothing-here')->assertNotFound()->assertSee('credential store')->assertDontSee('aria-label="Main"', false);
    }

    public static function unsafeReturns(): array
    {
        return [['//evil.example'], ['https://evil.example'], ['/\\evil'], ['/ok//evil.example'], ["/a\nb"], ['evil'], ['/confirm?return=/'], [''], [['/a']]];
    }

    #[DataProvider('unsafeReturns')]
    public function test_confirm_ignores_unsafe_return_targets(mixed $return): void
    {
        $this->signIn('owner', time() - 301);
        $this->post('/confirm', ['current_password' => self::PASSWORD, 'return' => $return])->assertRedirect('/');
    }
}
