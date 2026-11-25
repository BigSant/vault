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
        // A role with one place to go (viewer: Entries) gets no nav row; the VAULT mark leads to the entries.
        $response = $this->get('/')->assertOk()->assertSee('<a class="mark" href="/?workspace='.$this->workspace.'">VAULT</a>', false);
        $role === 'viewer' ? $response->assertDontSee('aria-label="Main"', false) : $response->assertSee('<nav class="nav" aria-label="Main" data-refresh="nav">', false);
        $response
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
        $this->post($this->base.'/projects', ['name' => '', 'back' => 'structure', 'confirm_password' => self::PASSWORD])->assertStatus(303)->assertSessionHas('form_errors');
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
            ->assertSee('aria-label="Agents sections"', false)->assertSee('Identities')->assertSee('<button>Sign out</button>', false)->assertDontSee('aria-label="Sign out"', false)->assertDontSee('Lock');
        $this->get('/account')->assertOk()->assertSee('Change password and sign out')->assertSee('<span class="chip role">owner</span>', false)->assertDontSee('>Lock<', false);
        // A signed-in person keeps the header on an error page; a guest gets the bare page.
        $this->get('/nothing-here')->assertNotFound()->assertSee('Not found')->assertDontSee('not refilled')
            ->assertSee('aria-label="Main"', false)->assertSee('<button>Sign out</button>', false)->assertSee('Back to entries</a>', false)->assertSessionHas('last_page', '/account');
        $this->get('/api/nothing-here')->assertNotFound();
    }

    public function test_a_form_that_fails_validation_comes_back_with_errors_and_non_secret_input(): void
    {
        $this->signIn('owner');
        // Browsers send no Referer here; the last page shown is the way back.
        $this->get($this->base.'/structure?from=test')->assertOk()->assertDontSee('id="form-errors"', false);
        $this->post($this->base.'/projects', ['name' => '', 'back' => 'structure', 'note' => 'kept text', 'secret' => 'synthetic-secret', 'secret_fields' => ['token' => 'synthetic-field'],
            'import' => 'synthetic-import', 'password' => 'synthetic-pass', 'password_confirmation' => 'synthetic-pass', 'current_password' => 'synthetic-current',
            'confirm_password' => self::PASSWORD, 'code' => '123456', 'token' => 'synthetic-token', 'bootstrap_code' => 'synthetic-boot', 'api_secret_key' => 'synthetic-lookalike'])
            ->assertStatus(303)->assertRedirect($this->base.'/structure?from=test')->assertSessionHas('form_errors', fn ($errors) => array_keys($errors) === ['name'])
            ->assertSessionHas('_old_input', ['name' => '', 'back' => 'structure', 'note' => 'kept text'])->assertSessionHas('error_form', $this->base.'/projects');
        $stored = json_encode(session()->all());
        foreach (['synthetic-', self::PASSWORD, '123456', 'password', 'secret', 'import', 'bootstrap_code'] as $never) {
            $this->assertStringNotContainsString($never, $stored);
        }
        $this->assertSame(0, DB::table('projects')->count());
        // The form page shows the summary once: an alert that can take focus, each message tied to its field name.
        $this->get($this->base.'/structure?from=test')->assertOk()->assertSee('id="form-errors" role="alert" tabindex="-1" data-form="'.$this->base.'/projects"', false)
            ->assertSee('<li data-field="name">', false)->assertSee('Check the form')->assertSee('not refilled')->assertSee('aria-label="Main"', false);
        $this->get($this->base.'/structure?from=test')->assertOk()->assertDontSee('id="form-errors"', false);

        // A same-origin Referer wins over the last page; a foreign one is never followed.
        $this->post($this->base.'/projects', ['name' => ''], ['Referer' => rtrim(config('app.url'), '/').$this->base.'/types'])->assertStatus(303)->assertRedirect($this->base.'/types');
        $this->post($this->base.'/projects', ['name' => ''], ['Referer' => 'https://evil.example/workspaces/x'])->assertStatus(303)->assertRedirect($this->base.'/structure?from=test');
        $this->post('/workspaces', ['name' => ''], ['Referer' => 'https://evil.example/'])->assertStatus(303)->assertRedirect($this->base.'/structure?from=test');

        // JSON callers and the API keep the plain 422; other errors keep the error page.
        $this->postJson($this->base.'/projects', ['name' => ''])->assertUnprocessable()->assertExactJson(['error' => 'invalid_input']);
        $this->post($this->base.'/projects', ['name' => ''], ['Accept' => 'application/json'])->assertUnprocessable()->assertExactJson(['error' => 'invalid_input']);
        $this->post('/workspaces/'.Str::uuid().'/projects', ['name' => 'shop'])->assertNotFound()->assertSee('Not found');
    }

    public function test_confirm_page_keeps_the_header_and_is_never_the_way_back(): void
    {
        $this->signIn('owner');
        $this->get('/account')->assertOk();
        $this->get('/confirm?return='.urlencode($this->base.'/types'))->assertOk()->assertSee('<title>Confirm your identity · Vault</title>', false)
            ->assertSeeInOrder(['<header', '<h1>Confirm your identity</h1>', '<form class="action" method="post" action="/confirm">'], false)
            ->assertSee('aria-label="Main"', false)->assertSessionHas('last_page', '/account');
        $this->get('/workspaces/new')->assertOk()->assertSee('<h1>Add a workspace</h1>', false)->assertSee('+ Add a workspace')->assertDontSee('New workspace');
        $this->post('/workspaces', ['name' => str_repeat('n', 101)])->assertStatus(303)->assertRedirect('/workspaces/new');
        $this->get('/workspaces/new')->assertOk()->assertSee('value="'.str_repeat('n', 101).'"', false)->assertSee('id="form-errors"', false);
    }

    public function test_error_pages_stay_bare_for_guests_and_unfinished_sign_ins(): void
    {
        $this->get('/nothing-here')->assertNotFound()->assertSee('Not found')->assertSee('credential store')->assertDontSee('aria-label="Main"', false);
        // A guest has no entries to go back to: the bare page always offers Sign in.
        $this->get('/nothing-here')->assertSee('href="/login">Sign in</a>', false)->assertDontSee('Back to entries');
        $user = User::create(['id' => (string) Str::uuid(), 'name' => 'Half Signed', 'email' => 'half@example.test', 'password' => Hash::make(self::PASSWORD), 'mfa_enabled' => true]);
        $this->actingAs($user)->get('/nothing-here')->assertNotFound()->assertSee('credential store')->assertDontSee('aria-label="Main"', false);
    }

    public function test_confirm_page_shows_the_workspace_of_the_blocked_action_and_no_active_nav_item(): void
    {
        $other = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $other, 'name' => 'Another space']);
        $user = $this->signIn('owner', time() - 301);
        DB::table('members')->insert(['workspace_id' => $other, 'user_id' => $user->id, 'role' => 'owner']);
        $project = (string) Str::uuid();
        $environment = (string) Str::uuid();
        $entry = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $this->workspace, 'name' => 'shop']);
        DB::table('environments')->insert(['id' => $environment, 'project_id' => $project, 'name' => 'stage']);
        DB::table('entries')->insert(['id' => $entry, 'environment_id' => $environment, 'name' => 'Admin', 'alias' => 'admin', 'type' => 'login', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // The last workspace opened is another one; the blocked action still decides the header.
        $this->get('/?workspace='.$other)->assertOk()->assertSee('aria-label="Workspace: Another space"', false);
        $this->post($this->base.'/projects', ['name' => 'shop'])->assertForbidden()->assertSee('action="/confirm"', false)
            ->assertSee('aria-label="Workspace: Main space"', false)->assertSee('href="'.$this->base.'/requests"', false)->assertDontSee('class="btn on"', false);
        $this->get('/?workspace='.$other)->assertOk();
        $this->post('/entries/'.$entry.'/reveal')->assertForbidden()->assertSee('action="/confirm"', false)
            ->assertSee('aria-label="Workspace: Main space"', false)->assertDontSee('class="btn on"', false)->assertDontSee('aria-current="page"', false);
        // A workspace the person is not in is never shown: the header falls back to the last one.
        $this->get('/?workspace='.$other)->assertOk();
        $this->post('/workspaces/'.Str::uuid().'/projects', ['name' => 'shop'])->assertNotFound()->assertSee('aria-label="Workspace: Another space"', false);
        // Without a route workspace and without a last one, the confirm page shows the first workspace instead of none.
        $this->withSession(['last_workspace' => null])->get('/confirm')->assertOk()->assertSee('aria-label="Workspace: Another space"', false)->assertDontSee('class="btn on"', false);
        // Error pages mark no nav item either, whatever their address.
        $this->get('/entries/'.Str::uuid())->assertNotFound()->assertSee('aria-label="Main"', false)->assertDontSee('class="btn on"', false);
    }

    public function test_validation_messages_use_the_visible_labels_and_the_summary_sits_under_the_page_head(): void
    {
        $this->signIn('owner');
        $this->get($this->base.'/agents')->assertOk();
        $this->post($this->base.'/machines', ['name' => 'agent', 'days' => 99])->assertStatus(303)
            ->assertSessionHas('form_errors', fn ($errors) => $errors['days'] === ['Valid for (days) must be 30 or less.'])->assertSessionHas('error_unfilled', false);
        // Under the h1 and the tabs, never above them; no secret was typed, so nothing is said about refilling.
        $this->get($this->base.'/agents')->assertOk()->assertSeeInOrder(['<h1>Agents</h1>', 'aria-label="Agents sections"', '</nav>', 'id="form-errors"', 'Valid for (days)'], false)
            ->assertDontSee('not refilled');
        // A label that differs on one form: "First password" for a new member, "New password" on the account page.
        $this->post($this->base.'/members', ['name' => 'New Person', 'email' => 'new@example.test', 'password' => 'short', 'role' => 'viewer'])->assertStatus(303)
            ->assertSessionHas('form_errors', fn ($errors) => $errors['password'] === ['First password must be at least 14 characters.'])->assertSessionHas('error_unfilled', true);
        $this->get($this->base.'/agents')->assertOk()->assertSee('Secret fields are not refilled.');
        $this->post('/password', ['current_password' => self::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(303)
            ->assertSessionHas('form_errors', fn ($errors) => $errors['password'] === ['New password must be at least 14 characters.']);
        $this->post('/password', ['current_password' => self::PASSWORD, 'password' => str_repeat('a', 14), 'password_confirmation' => str_repeat('b', 14)])->assertStatus(303)
            ->assertSessionHas('form_errors', fn ($errors) => $errors['password'] === ['New password and its repeat do not match.']);
        // A wrong current password comes back to the form like any other error; the password is not changed.
        $this->post('/password', ['current_password' => 'wrong-password', 'password' => str_repeat('a', 14), 'password_confirmation' => str_repeat('a', 14)])->assertStatus(303)
            ->assertSessionHas('form_errors', ['current_password' => ['Current password is not right.']]);
        $this->assertTrue(Hash::check(self::PASSWORD, User::where('email', 'owner@example.test')->value('password')));
        $this->assertAuthenticated();
        // The summary carries the short non-secret values that were sent, so app.js can find the one form among
        // several that post to the same address. Passwords are never among them.
        $this->post($this->base.'/environments', ['project_id' => 'p-123', 'name' => '', 'back' => 'structure', 'confirm_password' => 'never-shown'])->assertStatus(303);
        $this->get($this->base.'/structure')->assertOk()->assertSee('data-sent="{&quot;project_id&quot;:&quot;p-123&quot;', false)->assertDontSee('never-shown');
        $this->post($this->base.'/operations/'.Str::uuid().'/mfa', ['code' => 'abc'])->assertStatus(303)
            ->assertSessionHas('form_errors', fn ($errors) => $errors['code'] === ['Site MFA code must be 6 to 8 digits.']);
        // Every rule the forms use has its own sentence: the label first, never "The … field".
        $this->post($this->base.'/machines', ['name' => '', 'days' => 0])->assertStatus(303)
            ->assertSessionHas('form_errors', ['name' => ['Name is required.'], 'days' => ['Valid for (days) must be 1 or more.']]);
        $this->post($this->base.'/machines', ['name' => str_repeat('n', 101), 'days' => 'many'])->assertStatus(303)
            ->assertSessionHas('form_errors', ['name' => ['Name must be 100 characters or fewer.'], 'days' => ['Valid for (days) must be a whole number.']]);
        $this->post($this->base.'/members', ['name' => 'New Person', 'email' => 'not-an-email', 'role' => 'boss'])->assertStatus(303)
            ->assertSessionHas('form_errors', ['email' => ['Email must be a valid email address.'], 'role' => ['Role is not one of the allowed choices.']]);
        $messages = require base_path('lang/en/validation.php');
        foreach (\Illuminate\Support\Arr::dot(\Illuminate\Support\Arr::except($messages, ['attributes', 'form_attributes'])) as $key => $text) {
            $this->assertStringNotContainsString('field', $text, $key);
            $this->assertStringEndsWith('.', $text, $key);
        }
    }

    public function test_confirm_page_status_is_announced_and_describes_the_password_field(): void
    {
        $this->signIn('owner', time() - 301);
        $this->get('/confirm')->assertOk()->assertDontSee('id="confirm-status"', false)->assertDontSee('aria-describedby', false);
        $this->post($this->base.'/projects', ['name' => 'shop'])->assertForbidden()
            ->assertSee('<div class="callout warn" id="confirm-status" role="status">', false)->assertSee('aria-describedby="confirm-status"', false)->assertDontSee('aria-invalid', false);
        $this->post('/confirm', ['current_password' => 'wrong-password', 'return' => '/account'])->assertUnauthorized()
            ->assertSee('<div class="callout bad" id="confirm-status" role="alert">', false)->assertSee('aria-describedby="confirm-status"', false)->assertSee('aria-invalid="true"', false);
    }

    public function test_activity_names_events_in_words_with_the_code_as_inline_text(): void
    {
        $this->signIn('owner');
        $machine = (string) Str::uuid();
        $known = (string) Str::uuid();
        DB::table('machines')->insert(['id' => $machine, 'workspace_id' => $this->workspace, 'name' => 'ci-agent', 'token_hash' => hash('sha256', $machine), 'expires_at' => now()->addDay()]);
        DB::table('operations')->insert(['id' => $known, 'machine_id' => $machine, 'grant_id' => (string) Str::uuid(), 'entry_id' => (string) Str::uuid(),
            'action' => 'shop.admin-login', 'version' => 1, 'state' => 'denied', 'request_id' => 'r-'.$machine, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['operation.request', $known, 'ok'], ['operation.request', (string) Str::uuid(), 'denied'], ['entry.rotate', (string) Str::uuid(), 'ok']] as [$event, $subject, $outcome]) {
            DB::table('audit')->insert(['workspace_id' => $this->workspace, 'actor' => 'machine:'.$machine, 'event' => $event, 'subject' => $subject, 'outcome' => $outcome, 'created_at' => now()]);
        }
        $this->get($this->base.'/audit')->assertOk()
            ->assertSee('<span class="event-name">Agent requested <span class="name">shop.admin-login</span></span> <small class="nobreak event-code">request.open</small>', false)
            ->assertSee('<span class="event-name">Agent request</span>', false)->assertSee('<span class="event-name">Rotated entry values</span>', false)
            ->assertSee('<span class="chip bad">denied</span>', false)->assertDontSee('<code class="nobreak event-code">', false)->assertDontSee('Agent requested an action');
    }

    public function test_page_head_end_is_after_the_h1_its_head_block_and_the_tabs(): void
    {
        $end = fn (string $html) => substr($html, 0, \App\Providers\AppServiceProvider::headEnd($html));
        $this->assertSame('<h1>Account</h1>', $end('<h1>Account</h1><table></table>'));
        $this->assertSame('<a>Back</a><div class="head"><div><div class="title-row"><h1 class="name">A</h1><a>Edit</a></div><p>sub</p></div></div>',
            $end('<a>Back</a><div class="head"><div><div class="title-row"><h1 class="name">A</h1><a>Edit</a></div><p>sub</p></div></div><div>next</div>'));
        $this->assertSame("<h1>Setup</h1>\n<nav class=\"tabs\" aria-label=\"x\"><a>1</a></nav>\n<nav class=\"subnav\"><a>2</a></nav>",
            $end("<h1>Setup</h1>\n<nav class=\"tabs\" aria-label=\"x\"><a>1</a></nav>\n<nav class=\"subnav\"><a>2</a></nav>\n<h2>Projects</h2>"));
        $this->assertSame('', $end('<p>No heading</p>'));
    }

    public function test_requests_refresh_in_place_regions_and_round_11_wording(): void
    {
        // Setup mode: the activation code comes first.
        $this->get('/login')->assertOk()->assertSeeInOrder(['<span>Activation code</span>', '<span>Name</span>', '<span>Email</span>', '<span>Password</span>'], false);
        $this->signIn('owner');
        // The request sections sit in regions app.js replaces in place. The button and the time of the last check sit beside the
        // waiting heading, outside every region; without script the button is a plain link.
        $this->get($this->base.'/requests')->assertOk()
            ->assertSeeInOrder(['<div class="section-head">', '<h2 id="waiting" data-refresh="waiting-title">',
                '<a class="btn" href="'.$this->base.'/requests" data-refresh-now>Refresh requests</a>', 'data-refresh-status', 'Checked ', ' UTC</span>',
                '<div data-refresh="waiting" data-refresh-url="'.$this->base.'/requests">', '<div data-refresh="history" data-refresh-url="'.$this->base.'/requests">', '<h2 id="history">'], false)
            ->assertDontSee('>Refresh</a>', false);
        // Polling does not stop while the queue is empty; a hidden tab pauses. An expired row keeps the focus on its status.
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString('if (running || (!manual && (document.hidden || Date.now() < due))) return;', $script);
        $this->assertStringContainsString('chip.focus({ preventScroll: true })', $script);
        $this->assertStringNotContainsString("getElementById('content')?.focus", $script);
        $this->get($this->base.'/structure')->assertOk()->assertSee('<h2 class="setup-first">Environments by project</h2>', false)->assertDontSee('<h2>Projects', false)
            ->assertSee('placeholder="e.g. billing"', false)->assertSee('<span class="phone-only">Production</span>', false);
        $this->assertStringContainsString("[data-refresh]", (string) file_get_contents(public_path('app.js')));
        $this->assertStringNotContainsString('location.replace', (string) file_get_contents(public_path('app.js')));
    }

    public function test_account_wording_and_plain_footer(): void
    {
        $this->signIn('owner');
        $this->get('/account')->assertOk()->assertSee('<span>Repeat new password</span>', false)->assertSee('<button class="plain" aria-label="Sign out of this session">Sign out</button>', false)
            ->assertDontSee('class="foot"', false)->assertDontSee('class="lamp', false);
        // The footer line belongs to the Entries pages and the Agents area only.
        $foot = '<p class="foot">Secret values stay in the vault. Agents only get action results.</p>';
        $this->get('/?workspace='.$this->workspace)->assertOk()->assertSee($foot, false);
        foreach (['requests', 'agents', 'grants'] as $tab) {
            $this->get($this->base.'/'.$tab)->assertOk()->assertSee($foot, false);
        }
        foreach (['members', 'structure', 'types', 'audit'] as $other) {
            $this->get($this->base.'/'.$other)->assertOk()->assertDontSee('class="foot"', false);
        }
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
