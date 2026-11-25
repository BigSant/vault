<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
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
        $role === 'viewer' ? $response->assertDontSee('aria-label="Main"', false) : $response->assertSee('<nav class="nav" aria-label="Main" data-refresh="nav"'.($role === 'owner' ? ' data-waiting-url="'.$this->base.'/waiting"' : '').'>', false);
        $response
            ->assertSee('href="/account"', false)->assertSee('action="/logout"', false)->assertSee('href="/workspaces/new"', false)
            ->assertDontSee('<h3>Password confirmation', false)->assertDontSee('Change sign-in password');
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
            ->assertSeeInOrder(['aria-label="Setup sections"', 'href="'.$this->base.'/structure"', 'class="on" aria-current="page"', 'Projects', 'href="'.$this->base.'/types"', 'Entry types'], false)
            // The add form is reached from the head row, as on Members; the tabs follow it directly, with no jump links.
            ->assertSee('<div class="head"><h1>Setup</h1><a class="btn" href="#add-project">+ Add a project</a></div>'."\n".'<nav class="tabs"', false)
            ->assertSee('<h2 id="add-project">', false)->assertDontSee('On this page');
        $this->get($this->base.'/types')->assertOk()
            ->assertSee('<a class="btn on" href="'.$this->base.'/structure" aria-current="page">Setup</a>', false)
            ->assertSeeInOrder(['aria-label="Setup sections"', 'Projects', 'href="'.$this->base.'/types"', 'class="on" aria-current="page"', 'Entry types'], false)
            ->assertSee('<div class="head"><h1>Setup</h1><a class="btn" href="#add-type">+ Add an entry type</a></div>'."\n".'<nav class="tabs"', false)
            // Headings, links and buttons of this tab say "entry type"; a field label may say "Type".
            ->assertSeeInOrder(['<h2 id="add-type">Add an entry type</h2>', '<span>Type name</span>', '<button class="go">Add entry type</button>'], false)
            ->assertDontSee('Add a type')->assertDontSee('>Add type<', false);
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
        // The count alone, for app.js on the pages that do not refresh their requests: owners only.
        $this->getJson($this->base.'/waiting')->assertOk()->assertExactJson(['waiting' => 2]);
        $this->get('/workspaces/'.$other.'/waiting')->assertNotFound();
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString("document.querySelector('nav.nav[data-waiting-url]')", $script);
        $this->assertStringContainsString('if (stopped || running || document.hidden || Date.now() < due || Date.now() - used > 900000) return;', $script);
        $this->signIn('editor');
        $this->get('/')->assertOk()->assertDontSee('Requests waiting for you')->assertDontSee('data-waiting-url', false);
        $this->get($this->base.'/waiting')->assertNotFound();
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
            ->assertSee('<th scope="row">Password confirmation</th>', false)->assertSee('valid</span> until', false)->assertSee('action="/password"', false)->assertSee('action="/logout"', false)
            ->assertDontSee('name="return" value="/account"', false);
        $this->withSession(['confirmed_at' => time() - 301])->get('/account')->assertOk()->assertSee('<span class="chip muted">needed</span>', false)->assertSee('<h3>Password confirmation<small>', false)
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

    public function test_inline_confirmation_is_a_line_while_valid_and_a_field_only_when_required(): void
    {
        // Valid: "Confirmed until HH:MM UTC.", the field hidden and switched off. (The view composer sets $confirmedUntil.)
        $this->signIn('owner', time() - 100);
        $until = time() + 200;
        $valid = $this->get($this->base.'/structure')->assertOk()->getContent();
        $block = substr($valid, (int) strpos($valid, '<div class="confirm-inline"'), 900);
        $this->assertMatchesRegularExpression('/Password confirmed until <span class="nobreak">('.gmdate('H:i', $until).'|'.gmdate('H:i', $until + 2).') UTC<\/span>\./', $block);
        $this->assertStringContainsString('<label data-when="expired"  hidden >', $block);
        $this->assertMatchesRegularExpression('/name="confirm_password"[^>]* disabled >/', $block);
        $this->assertStringNotContainsString('is-needed', $valid);
        // Expired inside a form: the field is the form's own and required, with or without script.
        $expired = $this->withSession(['confirmed_at' => time() - 301])->get($this->base.'/structure')->assertOk()->getContent();
        $this->assertStringContainsString('class="confirm-inline is-needed"', $expired);
        $this->assertMatchesRegularExpression('/name="confirm_password"[^>]* required >/', $expired);
        session()->forget('confirmed_at');
        $this->view('confirm-inline', ['verb' => 'save'])
            ->assertSee('class="confirm-inline is-needed"', false)->assertSee('Enter your current password to save.')
            ->assertSee('autocomplete="current-password"', false)->assertSee(' required ', false)->assertDontSee(' disabled ', false);
        // Expired and shared: without script the field could not be sent, so the confirm page is named as the fallback.
        $this->view('confirm-inline', ['confirmedUntil' => null, 'verb' => 'decide', 'shared' => 'confirm-decide'])
            ->assertSee('id="confirm-decide"', false)->assertSee('You will be asked for your password first.')
            ->assertSee('data-script-text="Your password confirmation has expired. Enter your current password to decide."', false)
            ->assertSee('id="confirm-decide-password"', false)->assertSee(' disabled ', false)->assertDontSee(' required ', false);
    }

    public function test_a_waiting_request_is_decided_with_the_inline_password_and_shows_its_id_before_the_controls(): void
    {
        $this->signIn('owner', time() - 301);
        $this->waitingRequest();
        $id = (string) DB::table('operations')->value('id');
        $rid = substr($id, 0, 8);
        $row = (object) ['id' => $id, 'state' => 'pending', 'agent' => 'agent', 'action' => 'test.http', 'entry' => 'Shop key', 'entry_id' => 'e1', 'project' => 'shop',
            'environment' => 'stage', 'production' => false, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()];
        $page = $this->view('operations', ['operations' => collect([$row, (object) (['id' => 'ffffffff-2'] + (array) $row)]), 'mode' => 'waiting', 'workspace' => $this->workspace,
            'refreshUrl' => $this->base.'/requests', 'confirmedUntil' => null]);
        // One shared password block above the waiting board, outside the refreshed region; the id sits with the
        // action, before the controls, and ends each decision's accessible name, so twins can be told apart.
        $page->assertSeeInOrder(['id="confirm-decide"', 'data-refresh="waiting"', 'Request '.$rid.'</small>', 'data-confirm-from="confirm-decide"',
            ', request '.$rid.'">Allow once</button>', ', request '.$rid.'">Deny</button>', 'Request ffffffff</small>', ', request ffffffff">Allow once</button>'], false);
        $this->assertSame(1, substr_count((string) $page, 'id="confirm-decide"'));
        // A decision pressed without the password is held and named by a button in the password block; the block
        // asks for the password in the words of this page.
        $page->assertSee('data-script-text="Your password confirmation has expired. Enter your current password, then choose a request action below."', false)
            ->assertSee('data-held-name="Allow once: test.http, request '.$rid.'">Allow once</button>', false)->assertSee('data-held-name="Deny: test.http, request '.$rid.'">Deny</button>', false)
            ->assertSee('<p class="held" data-held-row hidden><span class="held-what" id="confirm-decide-held-what" data-held-what hidden></span><button type="button" class="go" data-held aria-describedby="confirm-decide-held-what"></button></p>', false)->assertSee('<small class="k">requests</small>', false)
            // The held decision keeps its context: the request's summary travels with its decision cell.
            ->assertSeeInOrder(['<td class="decision" data-label="Decision">', '<template data-held-summary><b class="name">', '</b> requests <code>test.http</code>', 'Request '.$rid.'</span></template>', 'data-confirm-from="confirm-decide"'], false);
        $this->assertStringContainsString("querySelector(':scope > template[data-held-summary]')", (string) file_get_contents(public_path('app.js')));
        $this->view('operations', ['operations' => collect([(object) (['state' => 'succeeded'] + (array) $row)]), 'mode' => 'history', 'workspace' => $this->workspace])
            ->assertSee('<small class="k">requested</small>', false)->assertDontSee('<small class="k">requests</small>', false);
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString("button?.dataset.heldName || button?.getAttribute('aria-label')", $script);
        $this->assertStringContainsString("'Now choose an action below.'", $script);
        // An expired confirmation with the password in the same POST decides at once: no confirm page.
        $this->post($this->base.'/operations/'.$id.'/decision', ['decision' => 'deny', 'confirm_password' => self::PASSWORD])->assertRedirect();
        $this->assertSame('revoked', DB::table('operations')->where('id', $id)->value('state'));
        $this->assertStringNotContainsString('confirm_password', json_encode(session()->all()));
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
        // The form page shows the summary once: it can take focus (which announces it, so no role="alert"), each message tied to its field name.
        $this->get($this->base.'/structure?from=test')->assertOk()->assertSee('id="form-errors" tabindex="-1" data-form="'.$this->base.'/projects"', false)->assertDontSee('id="form-errors" role="alert"', false)
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
        $this->get('/confirm?return='.urlencode($this->base.'/types'))->assertOk()->assertSee('<title>Password confirmation · Vault</title>', false)
            ->assertSeeInOrder(['<header', '<h1>Password confirmation</h1>', '<form class="action" method="post" action="/confirm">'], false)
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
        // A label that differs on one form: "Initial password" for a new member, "New password" on the account page.
        $this->post($this->base.'/members', ['name' => 'New Person', 'email' => 'new@example.test', 'password' => 'short', 'role' => 'viewer'])->assertStatus(303)
            ->assertSessionHas('form_errors', fn ($errors) => $errors['password'] === ['Initial password must be at least 14 characters.'])->assertSessionHas('error_unfilled', true);
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
        $this->assertSame("<div class=\"head\"><h1>Setup</h1><a class=\"btn\" href=\"#add-project\">+ Add a project</a></div>\n<nav class=\"tabs\" aria-label=\"x\"><a>1</a></nav>",
            $end("<div class=\"head\"><h1>Setup</h1><a class=\"btn\" href=\"#add-project\">+ Add a project</a></div>\n<nav class=\"tabs\" aria-label=\"x\"><a>1</a></nav>\n<h2>Projects</h2>"));
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
                '<a class="btn" href="'.$this->base.'/requests" data-refresh-now>Refresh requests</a>', 'data-refresh-status', 'Queue checked ', ' UTC</span>',
                '<div data-refresh="waiting" data-refresh-url="'.$this->base.'/requests">', '<div data-refresh="history" data-refresh-url="'.$this->base.'/requests">', '<h2 id="history">'], false)
            ->assertDontSee('>Refresh</a>', false);
        // Polling does not stop while the queue is empty; a hidden tab pauses. An expired row keeps the focus on its status.
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString('if (running || (!manual && (document.hidden || Date.now() < due || Date.now() - used > 900000))) return;', $script);
        $this->assertStringContainsString('chip.focus({ preventScroll: true })', $script);
        $this->assertStringNotContainsString("getElementById('content')?.focus", $script);
        // A board is merged row by row: only the row in use is kept, its siblings change around it, and the heading counts the rows
        // shown. A round that failed or left a stale row behind does not advance the time.
        $this->assertStringContainsString("const kept = [...region.querySelectorAll('table.requests tbody tr[data-request]')]", $script);
        $this->assertStringContainsString('body.insertBefore(document.adoptNode(row), kept[at] || null);', $script);
        $this->assertStringContainsString("'Last updated ' + last + ' UTC \\u00B7 ' + why", $script);
        $this->assertStringContainsString('else status(delayed());', $script);
        $this->assertStringNotContainsString('if (inUse(region)) { skipped = true; return; }', $script);
        $this->get($this->base.'/structure')->assertOk()->assertDontSee('<h2>Projects', false)->assertDontSee('Environments by project')->assertDontSee('<h2>Environments</h2>', false)
            // No h2 repeats the active tab: the project cards follow the tabs, and the two forms keep their h2.
            ->assertSeeInOrder(['</nav>', '<p class="note">A project groups entries by environment.', '<div class="project-list">', '<h2 id="add-project">Add a project</h2>', '<h2 id="template">Environment template</h2>'], false)
            ->assertSee('placeholder="e.g. billing"', false)->assertSee('<span class="phone-only">Production</span>', false);
        $this->assertStringContainsString("[data-refresh]", (string) file_get_contents(public_path('app.js')));
        $this->assertStringNotContainsString('location.replace', (string) file_get_contents(public_path('app.js')));
    }

    public function test_account_wording_and_plain_footer(): void
    {
        $this->signIn('owner');
        $this->get('/account')->assertOk()->assertSee('<span>Repeat new password</span>', false)->assertSee('<button aria-label="Sign out of this session">Sign out</button>', false)
            // Changing the password is no destructive action: the plain button, like Sign out.
            ->assertSee('<button>Change password and sign out</button>', false)
            ->assertSee('<form class="action quiet" method="post" action="/logout">', false)
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
        $this->get('/entries/new?workspace='.$this->workspace)->assertOk()->assertDontSee('class="foot"', false);
        // The status code of an error page is a chip under the h1, not inside it.
        $this->get('/nothing-here')->assertNotFound()->assertSeeInOrder(['<h1>Not found</h1>', '<p class="error-status"><span class="chip bad">Error 404</span></p>'], false);
        // The one-time token page keeps the head row of Identities, so the tabs do not move.
        $this->view('token', ['space' => (object) ['id' => $this->workspace], 'name' => 'deploy-bot', 'id' => 'a1', 'token' => 't', 'expiresAt' => now()])
            ->assertSeeInOrder(['<div class="head"><h1>Agents</h1></div>', '<nav class="tabs"', '<h2>Agent added</h2>'], false)
            // Leaving loses the token: no second way out in the head row, and the card guards every way out of the page
            // until the token is copied or marked as saved ("Token copied?", asked by app.js).
            ->assertDontSee('+ Add an agent')
            ->assertSeeInOrder(['<div class="card solo" data-leave-guard="Token copied?">', 'data-copy="agent-token" data-copy-name="token">Copy token</button>',
                '<button type="button" data-leave-clear hidden>I\'ve saved it</button>', '#add-grant">Add a grant</a>',
                '<a class="btn" href="'.$this->base.'/agents" data-leave-done>Back to Identities</a>'], false);
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString("document.querySelector('[data-leave-guard]')", $script);
        $this->assertStringContainsString("guard.addEventListener('vault:copied', lift)", $script);
        $operations = (string) file_get_contents(resource_path('views/operations.blade.php'));
        $this->assertStringContainsString('<th scope="col">Finished (UTC)</th>', $operations);
        $this->assertStringContainsString('data-label="Finished (UTC)"', $operations);
        $this->assertStringNotContainsString('Ended (UTC)', $operations);
        // An open fold reads "Close"; the token page warns beside its buttons; the Account h2 does not change.
        foreach (['structure', 'types'] as $view) {
            $this->assertStringContainsString('<span class="fold-act"><span class="when-closed">Edit</span><span class="when-open">Close</span>', (string) file_get_contents(resource_path('views/'.$view.'.blade.php')));
        }
        $this->view('token', ['space' => (object) ['id' => $this->workspace], 'name' => 'deploy-bot', 'id' => 'a1', 'token' => 't', 'expiresAt' => now()])->assertSee('Leaving this page loses the token.');
        foreach ([time(), time() - 301] as $confirmedAt) {
            $this->withSession(['confirmed_at' => $confirmedAt])->get('/account')->assertOk()->assertSeeInOrder(['<h2 id="security">Security</h2>', '<h3>Change sign-in password</h3>'], false);
        }
        // A field error follows the label and is the field's description, never part of its name; Requests and Grants are cards below 1180 px.
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString('anchor.after(note)', $script);
        $this->assertStringNotContainsString('holder.matches(\'label\') || field.hasAttribute', $script);
        $this->assertStringContainsString('@media (max-width:1180px){'."\n".'.board:is(.requests,.grants),', (string) file_get_contents(public_path('app.css')));
    }

    public function test_end_dates_read_the_same_on_identities_grants_and_agent_access(): void
    {
        // One partial: label, date and UTC, then the reason and the grant's own date on lines of their own.
        $revoked = (string) $this->view('end-date', ['revoked' => '2026-11-20T10:15:00Z', 'ends' => '2026-12-01 09:00:00', 'reason' => 'agent revoked', 'own' => '2026-12-01 09:00:00']);
        $this->assertStringContainsString('<small class="k keep">Revoked</small> <span class="nobreak">2026-11-20 10:15 <small class="k">UTC</small></span>', $revoked);
        $this->assertStringContainsString('<small class="wrap">agent revoked</small>', $revoked);
        $this->assertStringNotContainsString('Grant expiry', $revoked);
        $soon = now()->addDays(2)->format('Y-m-d H:i');
        $later = now()->addDays(9)->format('Y-m-d H:i');
        $active = (string) $this->view('end-date', ['revoked' => null, 'ends' => $soon.':00', 'reason' => 'agent expires first', 'own' => $later.':00']);
        $this->assertStringContainsString('<small class="k">Expires</small> <span class="nobreak">'.$soon.' <small class="k">UTC</small></span>', $active);
        $this->assertStringContainsString('<small class="wrap">Grant expiry: <span class="nobreak">'.$later.' UTC</span></small>', $active);
        $this->assertStringContainsString('<small class="k keep">Expired</small>', (string) $this->view('end-date', ['revoked' => null, 'ends' => '2020-01-01 00:00:00']));
        foreach (['agents', 'grants', 'entry'] as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view.'.blade.php'));
            $this->assertStringContainsString("@include('end-date', [", $source);
            $this->assertStringNotContainsString('Revoked <span', $source);
        }
        $this->signIn('owner');
        $this->get($this->base.'/agents')->assertOk()->assertSee('No agents yet.')->assertDontSee('No agent identities');
    }

    public function test_a_page_with_two_shared_confirmations_states_it_once(): void
    {
        // The entry page: while requests wait, their block is the one confirmation state; the Reveal block is
        // deferred (nothing while valid, a pointer when expired, set by app.js) and names the first block.
        $this->signIn('owner');
        $this->get('/account')->assertOk();
        $deferred = $this->view('confirm-inline', ['confirmedUntil' => time() + 200, 'verb' => 'reveal', 'shared' => 'confirm-reveal', 'primary' => 'confirm-decide', 'primaryShown' => true]);
        $deferred->assertSee('class="confirm-inline shared is-deferred is-quiet" id="confirm-reveal"  data-primary="confirm-decide"', false)
            ->assertSee('<span data-when="valid"  hidden >Password confirmed until', false)
            ->assertSee('<a class="more-link" href="#confirm-decide-password" data-confirm-goto="confirm-decide">Go to the password field</a> above the waiting requests', false);
        // Nothing waits: the Reveal block is the one that says it.
        $alone = $this->view('confirm-inline', ['confirmedUntil' => time() + 200, 'verb' => 'reveal', 'shared' => 'confirm-reveal', 'primary' => 'confirm-decide', 'primaryShown' => false]);
        $alone->assertSee('class="confirm-inline shared" id="confirm-reveal"', false)->assertSee('<span data-when="valid" >Password confirmed until', false);
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString('if (first && !first.box.hidden) state = first;', $script);
    }

    public function test_stylesheet_has_no_selector_keyed_on_label_text_and_views_use_no_undefined_class(): void
    {
        $css = (string) file_get_contents(public_path('app.css'));
        $this->assertDoesNotMatchRegularExpression('/\[(aria-label|data-label)="[^"]+"\]/', $css);
        foreach (['field-key', 'field-static', 'button.plain'] as $gone) {
            $this->assertStringNotContainsString($gone, $css);
        }
        foreach (['.chip.filter{', '.catalog-search{', '.more-link{', '.board tr.is-plain{'] as $defined) {
            $this->assertStringContainsString($defined, $css);
        }
        foreach (['account', 'entry-form', 'grants', 'agents', 'token', 'structure', 'types'] as $view) {
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*\b(plain|field-key|field-static)\b/', (string) file_get_contents(resource_path('views/'.$view.'.blade.php')));
        }
    }

    public function test_entry_versions_are_paged(): void
    {
        Http::fake(['*' => Http::response(['data' => ['versions' => []]], 200)]);
        $this->signIn('editor');
        $project = (string) Str::uuid();
        $environment = (string) Str::uuid();
        $entry = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $this->workspace, 'name' => 'shop']);
        DB::table('environments')->insert(['id' => $environment, 'project_id' => $project, 'name' => 'stage']);
        DB::table('entries')->insert(['id' => $entry, 'environment_id' => $environment, 'name' => 'Admin', 'alias' => 'admin', 'type' => 'login', 'version' => 60, 'created_at' => now(), 'updated_at' => now()]);
        // The entry page has no footer line; page 1 lists versions 60 down to 11 and links to the older ones.
        $this->get('/entries/'.$entry)->assertOk()->assertDontSee('class="foot"', false)
            ->assertSeeInOrder(['<nav class="pager" aria-label="Version pages">', 'Versions 11–60 of 60', '<a href="/entries/'.$entry.'?vpage=2#versions">Older versions →</a>'], false)
            ->assertDontSee('← Newer versions');
        // Page 2 lists the rest and links back.
        $this->get('/entries/'.$entry.'?vpage=2')->assertOk()
            ->assertSeeInOrder(['<a href="/entries/'.$entry.'#versions">← Newer versions</a>', 'Versions 1–10 of 60'], false)
            ->assertDontSee('Older versions →');
        // A page past the oldest version is empty, not an error.
        $this->get('/entries/'.$entry.'?vpage=9')->assertOk()->assertSee('No older versions.');
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

    public function test_entry_form_keeps_the_draft_through_a_setup_detour_and_takes_dates_as_iso_text(): void
    {
        $this->signIn('owner');
        $project = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $this->workspace, 'name' => 'shop']);
        DB::table('environments')->insert(['id' => (string) Str::uuid(), 'project_id' => $project, 'name' => 'stage']);
        $page = $this->get('/entries/new?workspace='.$this->workspace)->assertOk();
        // Setup opens in a new tab and says so; "Reload types" (shown by script) swaps the Type options and #type-fields only.
        $page->assertSee('/types" target="_blank" rel="noopener">Edit entry types (new tab)</a>', false)
            ->assertSeeInOrder(['<p class="type-detour" data-reload-types-row>', 'Edit entry types (new tab)</a>', '<button type="button" data-reload-types title=', ' hidden>Reload types</button>', 'data-reload-status></span></p>'], false)
            ->assertSeeInOrder(['id="entry-type"', '<fieldset id="type-fields">', '<legend>Tags and rotation</legend>'], false)
            ->assertSee('Agents refer to this entry by its alias')
            ->assertSee('<input type="text" pattern="\d{4}-\d{2}-\d{2}" placeholder="YYYY-MM-DD"', false)->assertDontSee('type="date"', false)
            // No number pad: it may have no "-". app.js turns 20261109, 2026/11/09 and 2026.11.09 into 2026-11-09.
            ->assertDontSee('inputmode="numeric" pattern="\d{4}', false);
        $this->get($this->base.'/audit')->assertOk()->assertSee('<label>From (UTC, YYYY-MM-DD)<input type="text" pattern="\d{4}-\d{2}-\d{2}" placeholder="YYYY-MM-DD" maxlength="10" autocomplete="off" title="A date as YYYY-MM-DD" name="from"', false)
            ->assertSee('<label>To (UTC, YYYY-MM-DD)<input type="text" pattern="\d{4}-\d{2}-\d{2}" placeholder="YYYY-MM-DD" maxlength="10" autocomplete="off" title="A date as YYYY-MM-DD" name="to"', false)
            ->assertDontSee('filter-errors', false);
        // A filter that cannot be used is named above the filters and left out; never an error page.
        $this->get($this->base.'/audit?from=2026-02-30')->assertOk()->assertSeeInOrder(['<div class="callout bad filter-errors" role="status">', 'A filter was not applied.', '<li>From (UTC) ', '<details class="filters"'], false);
        // Setup: each environment's edit fields name their environment and project.
        $this->get($this->base.'/structure')->assertOk()->assertSee('aria-label="Name of environment stage in project shop"', false)
            ->assertSee('aria-label="Production for environment stage in project shop"', false);
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString("match(/^(\\d{4})([-\\/.]?)(\\d{2})\\2(\\d{2})$/)", $script);
        $this->assertStringContainsString("input[placeholder=\"YYYY-MM-DD\"]", $script);
        $this->assertStringContainsString("document.querySelectorAll('[data-reload-types]')", $script);
        $this->assertStringContainsString('rows.replaceChildren(', $script);
        $this->get($this->base.'/agents')->assertOk()->assertSee('The agent can do nothing until you add a grant.');
    }

    public function test_header_and_page_share_one_right_edge_and_phone_cards_keep_their_lines(): void
    {
        $css = (string) file_get_contents(public_path('app.css'));
        // The header's contents end at --wide like main's; the bar stays full width.
        $this->assertStringContainsString('.top{--top-left:max(28px,calc((100% - 1760px)/2 + 28px));padding-left:var(--top-left);padding-right:max(28px,calc(100% - var(--wide) - var(--top-left)))}', $css);
        // Grants cards are flex lines, so the action code never sizes the Revoke column.
        $this->assertStringNotContainsString('.board.grants tr{display:grid', $css);
        // Entries on phone: two stats, then production and agent use as one summary line.
        $this->assertStringContainsString('.entry-stats>.stat:nth-child(n+3) b{display:inline;', $css);
        $this->assertStringNotContainsString('input[type=date]', $css);
        $this->view('token', ['name' => 'deploy-bot', 'id' => 'm1', 'token' => 'synthetic-token', 'expiresAt' => now()->addDays(7), 'space' => (object) ['id' => $this->workspace, 'role' => 'owner']])
            ->assertSee('<span class="token-expiry"><span class="sep" aria-hidden="true">· </span>Expires <span class="nobreak">', false);
    }
}
