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
    private string $connectors;
    private const PASSWORD = 'Synthetic-password-123!';

    private string $workspace;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        // Pages that list actions read the operator's connector file. Give every test its own, so the suite does not
        // depend on /opt/vault existing on the machine that runs it.
        $this->connectors = tempnam(sys_get_temp_dir(), 'vault-connectors-');
        file_put_contents($this->connectors, json_encode(['actions' => ['test.http' => ['kind' => 'http', 'url' => 'https://example.invalid/status']]]));
        config(['vault.connectors' => $this->connectors]);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->workspace = (string) Str::uuid();
        $this->base = '/workspaces/'.$this->workspace;
        DB::table('workspaces')->insert(['id' => $this->workspace, 'name' => 'Main space']);
    }

    protected function tearDown(): void
    {
        if (isset($this->connectors) && is_file($this->connectors)) {
            unlink($this->connectors);
        }
        parent::tearDown();
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
            ->assertSee('<a class="btn on" href="'.$this->base.'/structure" aria-current="location">Setup</a>', false)
            ->assertSeeInOrder(['aria-label="Setup sections"', 'href="'.$this->base.'/structure"', 'class="on" id="tab-current" aria-current="page"', 'Projects', 'href="'.$this->base.'/types"', 'Entry types'], false)
            // The add form is reached from the head row, as on Members; the tabs follow it directly, with no jump links.
            ->assertSee('<div class="head"><h1>Setup</h1><a class="btn" href="#add-project">+ Add a project</a></div>'."\n".'<nav class="tabs"', false)
            ->assertSee('<h2 id="add-project">', false)->assertDontSee('On this page');
        $this->get($this->base.'/types')->assertOk()
            ->assertSee('<a class="btn on" href="'.$this->base.'/structure" aria-current="location">Setup</a>', false)
            ->assertSeeInOrder(['aria-label="Setup sections"', 'Projects', 'href="'.$this->base.'/types"', 'class="on" id="tab-current" aria-current="page"', 'Entry types'], false)
            ->assertSee('<div class="head"><h1>Setup</h1><a class="btn" href="#add-type">+ Add an entry type</a></div>'."\n".'<nav class="tabs"', false)
            // Headings, links and buttons of this tab say "entry type"; a field label may say "Type".
            ->assertSeeInOrder(['<h2 id="add-type">Add an entry type</h2>', '<span>Type name</span>', '<button class="go" data-held-name="Add entry type " data-held-field="name">Add entry type</button>'], false)
            // One shared password block above the folds serves every form; no fold has a block of its own, and the
            // add form only points to it (with a field of its own in <noscript> once the confirmation has expired).
            ->assertSeeInOrder(['<div class="confirm-inline shared" id="confirm-types"', '<details class="fold type-fold capped"', 'data-confirm-from="confirm-types"', 'data-held-name="Save entry type ', '<h2 id="add-type">', 'data-confirm-from="confirm-types"', 'id="confirm-add-type"  data-primary="confirm-types"'], false)
            ->assertDontSee('<div class="confirm-inline"', false)
            ->assertDontSee('Add a type')->assertDontSee('>Add type<', false);
        $this->get('/')->assertOk()->assertSee('<a class="btn on" href="/?workspace='.$this->workspace.'" aria-current="page">Entries</a>', false);
        // Inside an area the nav item is the "location" and the tab is the "page"; a page of its own is the "page".
        $this->get('/entries/new?workspace='.$this->workspace)->assertOk()->assertSee('<a class="btn on" href="/?workspace='.$this->workspace.'" aria-current="location">Entries</a>', false);
        $this->signIn('owner');
        $this->get($this->base.'/grants')->assertOk()->assertSee('<a class="btn on" href="'.$this->base.'/requests" aria-current="location">Agents</a>', false)
            ->assertSeeInOrder(['aria-label="Agents sections"', 'href="'.$this->base.'/grants"', 'class="on" id="tab-current" aria-current="page"', 'Grants'], false);
        $this->get($this->base.'/members')->assertOk()->assertSee('<a class="btn on" href="'.$this->base.'/members" aria-current="page">Members</a>', false);
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

    public function test_a_wrong_confirm_password_alone_does_not_say_secret_fields_were_dropped(): void
    {
        // The shared password field is no form value: a wrong one on a form without secret fields leaves the summary
        // without "not refilled" and without data-unfilled, so app.js holds the action again for the next password.
        $this->signIn('owner', time() - 301);
        $this->get($this->base.'/structure')->assertOk();
        $this->post($this->base.'/projects', ['name' => 'shop', 'back' => 'structure', 'confirm_password' => 'wrong-password'])->assertStatus(303)
            ->assertSessionHas('form_errors.confirm_password', ['Current password is not right.'])->assertSessionHas('error_unfilled', false);
        $this->get($this->base.'/structure')->assertOk()->assertSee('<li data-field="confirm_password">Current password is not right.</li>', false)
            ->assertDontSee('Secret fields are not refilled.')->assertDontSee('data-unfilled', false);
        // A secret field typed beside it still says so.
        $this->get($this->base.'/members')->assertOk();
        $this->post($this->base.'/members', ['name' => 'New Person', 'email' => 'new@example.test', 'password' => 'a-long-initial-password', 'role' => 'viewer', 'confirm_password' => 'wrong-password'])
            ->assertStatus(303)->assertSessionHas('error_unfilled', true);
        $this->get($this->base.'/members')->assertOk()->assertSee('Secret fields are not refilled.')->assertSee('data-unfilled', false);
    }

    public function test_structure_and_wording(): void
    {
        $this->signIn('owner', time() - 301);
        // Requests: the history ends with the way to the whole log.
        $this->get($this->base.'/requests')->assertOk()
            ->assertSeeInOrder(['Recent requests', '<a class="more-link" href="'.$this->base.'/audit?group=request">All request activity →</a>'], false);
        // Account, confirmation needed: chip and link only in the facts row; the card keeps its subtitle; no foot note; no Sign out section.
        $this->get('/account')->assertOk()->assertSee('<span class="chip muted">needed</span> <a class="more-link" href="#confirm" data-focus="account-confirm-password">Confirm your password</a>', false)
            ->assertDontSee('to reveal values or change access')->assertDontSee('Reveals and access changes are written')
            ->assertSee('<h3>Password confirmation<small>Valid for 5 minutes.', false)->assertDontSee('<h2>Sign out</h2>', false)->assertSee('aria-label="Sign out of this session"', false);
        // No heading inside a summary, in any view.
        foreach (glob(resource_path('views/*.blade.php')) as $view) {
            $this->assertDoesNotMatchRegularExpression('~<summary[^>]*>(?:(?!</summary>).)*<h[1-6]~s', (string) file_get_contents($view), basename($view));
        }
        $entry = (string) file_get_contents(resource_path('views/entry.blade.php'));
        $this->assertStringContainsString('<th scope="row">Rotation reminder (UTC)</th>', $entry);
        // A viewer is told once what the role sees.
        $this->assertSame(1, substr_count($entry, 'Viewers see entry details and version history, not values.'));
        $this->assertStringNotContainsString('viewer role', $entry);
        // Phones: the values are one tap from the closed drawer, for a person who may reveal them.
        $this->assertStringContainsString('@if($canEdit)<a class="jump-quick" href="#values">Secret values <span aria-hidden="true">↓</span></a>@endif', $entry);
        $this->assertStringContainsString('Used by {{ $plural($used, \'entry\', \'entries\') }}, so it cannot be removed.', (string) file_get_contents(resource_path('views/types.blade.php')));
        $style = (string) file_get_contents(public_path('app.css'));
        $this->assertStringContainsString('.form-foot button.action-name{display:block;flex:0 1 auto;min-width:0;max-width:100%;white-space:normal;overflow-wrap:anywhere;text-align:left', $style);
        $this->assertStringContainsString('.import-example pre.cmd{white-space:pre-wrap;overflow-wrap:anywhere', $style);
        $this->assertStringContainsString('nav.subnav>details.jump[open]+a.jump-quick{display:none}', $style);
        // Grants: the shared password block stands above the filter bar, so bar, status line and board stay together.
        $grants = (string) file_get_contents(resource_path('views/grants.blade.php'));
        $this->assertLessThan(strpos($grants, '<details class="filters"'), strpos($grants, "'shared' => 'confirm-grants'"));
        // Hiding values moves the focus only from a control that goes: Hide values, or a Copy of these values.
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString("const losesFocus = hideNow.includes(active) || (active?.matches?.('[data-copy]') && secrets.some(el => el.id && el.id === active.dataset.copy));", $script);
        $this->assertStringContainsString('if (manual || losesFocus) again[0]?.focus();', $script);
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

    public function test_pending_revoke_resumes_only_after_an_explicit_post(): void
    {
        $this->signIn('owner', time() - 301);
        $this->waitingRequest();
        $path = $this->base.'/revoke/machines/'.DB::table('machines')->value('id');
        $this->post($path, ['back' => 'agents', 'password_hint' => 'private', 'token' => 'private', 'oversized' => str_repeat('a', 4097)])
            ->assertForbidden()->assertSee('Revoke agent <span class="name">agent</span>', false);
        $pending = session('pending_action');
        $this->assertSame(['back' => 'agents'], $pending['fields']);
        $this->post('/confirm', ['pending' => $pending['nonce'], 'current_password' => self::PASSWORD])
            ->assertOk()->assertSee('action="'.$path.'"', false)->assertSee('Revoke agent <span class="name">agent</span>', false)
            ->assertSee('name="back" value="agents"', false)->assertDontSee('private')->assertSessionMissing('pending_action');
        $this->assertNull(DB::table('machines')->value('revoked_at'));
        $this->post($path, $pending['fields'])->assertRedirect($this->base.'/agents');
        $this->assertNotNull(DB::table('machines')->value('revoked_at'));
    }

    public function test_pending_action_rejects_external_origin_unknown_route_and_expired_nonce(): void
    {
        $this->signIn('owner', time() - 301);
        $path = $this->base.'/projects';
        $this->post($path, ['name' => 'billing'], ['Origin' => 'https://other.example'])->assertForbidden()->assertSessionMissing('pending_action');
        $this->post('/unknown')->assertSessionMissing('pending_action');
        $this->post($path, ['name' => 'billing'])->assertForbidden()->assertSee('Add project <span class="name">billing</span>', false);
        $nonce = session('pending_action.nonce');
        $this->travel(11)->minutes();
        $this->post('/confirm', ['pending' => $nonce, 'current_password' => self::PASSWORD])
            ->assertRedirect('/')->assertSessionMissing('pending_action');
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
        // Import is a long form with a block of its own; Entry types has one shared block above its folds.
        $project = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $this->workspace, 'name' => 'shop']);
        DB::table('environments')->insert(['id' => (string) Str::uuid(), 'project_id' => $project, 'name' => 'stage']);
        $valid = $this->get($this->base.'/import')->assertOk()->getContent();
        $block = substr($valid, (int) strpos($valid, '<div class="confirm-inline"'), 900);
        $this->assertMatchesRegularExpression('/Password confirmed until <span class="nobreak">('.gmdate('H:i', $until).'|'.gmdate('H:i', $until + 2).') UTC<\/span>\./', $block);
        $this->assertStringContainsString('<label data-when="expired"  hidden >', $block);
        $this->assertMatchesRegularExpression('/name="confirm_password"[^>]* disabled >/', $block);
        $this->assertStringNotContainsString('is-needed', $valid);
        // Expired inside a form: the field is the form's own and required, with or without script.
        $expired = $this->withSession(['confirmed_at' => time() - 301])->get($this->base.'/import')->assertOk()->getContent();
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
            ->assertSee('<p class="held" data-held-row hidden><span class="held-what" id="confirm-decide-held-what" data-held-what hidden></span><button type="button" class="go" data-held></button></p>', false)->assertSee('<small class="k">requests</small>', false)
            // The visible guidance line is readable by everyone, and the heading carries the count as a chip.
            ->assertSee('<span class="pending" data-pending hidden></span>', false)->assertDontSee('aria-hidden="true" hidden', false)
            ->assertSee('data-refresh="waiting-title">Requests waiting for you <span class="chip warn" data-waiting-count>2<span class="sr-only"> waiting</span></span></h2>', false)
            // The held decision keeps its context: the request's summary travels with its decision cell.
            ->assertSeeInOrder(['<td class="decision" data-label="Decision">', '<template data-held-summary><b class="name">', '</b> requests <code>test.http</code>', 'Request '.$rid.'</span></template>', 'data-confirm-from="confirm-decide"'], false);
        $this->assertStringContainsString("querySelector(':scope > template[data-held-summary]')", (string) file_get_contents(public_path('app.js')));
        $this->view('operations', ['operations' => collect([(object) (['state' => 'succeeded'] + (array) $row)]), 'mode' => 'history', 'workspace' => $this->workspace])
            ->assertSee('<small class="k">requested</small>', false)->assertDontSee('<small class="k">requests</small>', false);
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString("button?.dataset.heldName || button?.getAttribute('aria-label')", $script);
        // The summary is the held button's description only while it holds something.
        $this->assertStringContainsString("describe(heldButton, heldWhat.id, Boolean(source));", $script);
        $this->assertStringContainsString("'Now choose an action below.'", $script);
        // An expired confirmation with the password in the same POST decides at once: no confirm page.
        $this->post($this->base.'/operations/'.$id.'/decision', ['decision' => 'deny', 'confirm_password' => self::PASSWORD])->assertRedirect();
        $this->assertSame('revoked', DB::table('operations')->where('id', $id)->value('state'));
        $this->assertStringNotContainsString('confirm_password', json_encode(session()->all()));
    }

    public function test_wrong_confirm_password_returns_to_the_form_with_an_error_and_is_never_flashed(): void
    {
        $this->signIn('owner', $stale = time() - 301);
        $this->get($this->base.'/structure')->assertOk();
        // A validation error on the field, back on the page that was sent: never the confirm page.
        $this->post($this->base.'/projects', ['name' => 'shop', 'back' => 'structure', 'confirm_password' => 'wrong-password'])->assertStatus(303)
            ->assertRedirect($this->base.'/structure')->assertSessionHas('form_errors.confirm_password', ['Current password is not right.'])
            ->assertSessionHas('error_form', $this->base.'/projects');
        $this->assertStringNotContainsString('wrong-password', json_encode(session()->all()));
        $this->get($this->base.'/structure')->assertOk()->assertSee('<li data-field="confirm_password">Current password is not right.</li>', false)->assertDontSee('wrong-password');
        $this->assertSame(0, DB::table('projects')->count());
        $this->assertSame($stale, session('confirmed_at'));
        // A query string is no place for a password, even a correct one.
        $this->post($this->base.'/projects?confirm_password='.urlencode(self::PASSWORD), ['name' => 'shop'])->assertForbidden();
        // A validation error flashes the other input back, never the password. The confirmation it refreshed stays.
        $this->post($this->base.'/projects', ['name' => '', 'back' => 'structure', 'confirm_password' => self::PASSWORD])->assertStatus(303)->assertSessionHas('form_errors');
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(session()->all()));
        $this->assertStringNotContainsString('confirm_password', json_encode(session()->all()));
    }

    public function test_members_row_actions_are_held_by_a_shared_password_block_and_never_sent_to_the_confirm_page(): void
    {
        $this->signIn('owner', time() - 301);
        $dana = User::create(['id' => (string) Str::uuid(), 'name' => 'Dana', 'email' => 'dana@example.test', 'password' => Hash::make(self::PASSWORD), 'mfa_enabled' => true]);
        DB::table('members')->insert(['workspace_id' => $this->workspace, 'user_id' => $dana->id, 'role' => 'viewer']);
        $page = $this->get($this->base.'/members')->assertOk();
        // One shared block above the board; both row forms and the add form name it, and each button carries the held
        // wording ("Change Dana's role to " + the chosen role, "Remove Dana", "Add member: " + the name typed).
        $page->assertSeeInOrder(['id="confirm-members"', 'class="board compact-board members"', 'data-confirm-from="confirm-members"'], false)
            ->assertDontSee('data-quiet-valid', false)
            ->assertSee('data-script-text="Your password confirmation has expired. Enter your current password, then save a role, remove a member or add a member below."', false)
            ->assertSee('/members" data-confirm-from="confirm-members">', false)
            ->assertSee('<button class="go" data-held-name="Add member: " data-held-field="name">Add member</button>', false)
            // The add form only points to that field; a field of its own exists without script alone.
            ->assertSeeInOrder(['id="confirm-add-member"  data-primary="confirm-members"', 'Password confirmation expired. <a class="more-link" href="#confirm-members-password" data-confirm-goto="confirm-members">Go to the password field <span aria-hidden="true">↑</span></a></span>', '<noscript><p class="note">Your password confirmation has expired. Enter your current password to add this member.</p><label><span>Current password</span><input type="password" name="confirm_password" maxlength="200" autocomplete="current-password" required></label></noscript>'], false)
            ->assertSee('data-held-name="Change Dana\'s role to " data-held-field="role" data-held-lead="Change " data-held-data="Dana\'s">Save role</button>', false)
            ->assertSee('data-confirm="Remove Dana from this workspace?" data-confirm-from="confirm-members">', false)
            ->assertSee('data-held-name="Remove Dana" data-held-lead="Remove " data-held-data="Dana">Remove</button>', false)
            ->assertSee('<small>Add another owner before changing your role.</small>', false)
            ->assertSee('id="first-password-hint">Blank for an existing account. At least 14 characters for a new one.</small>', false);
        $this->assertSame(1, substr_count($page->getContent(), 'id="confirm-members"'));
        $this->assertSame(3, substr_count($page->getContent(), 'data-confirm-from="confirm-members"'));
        // Outside <noscript> the page has one password field that can be used: the shared block's.
        $this->assertSame(1, substr_count((string) preg_replace('#<noscript>.*?</noscript>#s', '', $page->getContent()), 'id="confirm-members-password"'));
        $this->assertSame(0, substr_count((string) preg_replace('#<noscript>.*?</noscript>#s', '', $page->getContent()), 'name="confirm_password" maxlength="200" autocomplete="current-password" required'));
        // While the confirmation is valid the shared block states it once; the add form's block shows nothing and has no fallback field.
        $valid = $this->withSession(['confirmed_at' => time()])->get($this->base.'/members')->assertOk()
            ->assertSee('<div class="confirm-inline shared" id="confirm-members"', false)
            ->assertSee('<div class="confirm-inline shared is-deferred is-quiet" id="confirm-add-member"', false)->assertDontSee('<noscript>', false);
        $this->assertSame(1, preg_match_all('#<span data-when="valid" >Password confirmed until#', $valid->getContent()));
        // Add member reads the password from the same POST: a wrong one is a field error back on Members, never the confirm page.
        $this->withSession(['confirmed_at' => time() - 301])->post($this->base.'/members', ['form' => 'add', 'name' => 'Eli', 'email' => 'eli@example.test', 'password' => 'a-long-first-password', 'role' => 'viewer', 'confirm_password' => 'wrong-password'])
            ->assertStatus(303)->assertRedirect($this->base.'/members')->assertSessionHas('form_errors.confirm_password', ['Current password is not right.']);
        $script = (string) file_get_contents(public_path('app.js'));
        // The chosen role is part of the held wording and stays chosen; the held button does not ask "Remove?" again.
        $this->assertStringContainsString('button?.dataset.heldField ? form.elements[button.dataset.heldField] : null', $script);
        $this->assertStringContainsString("form.dataset.heldSend = '1';", $script);
        // A password that was not right is shown at the shared block's field, with the action held again.
        $this->assertStringContainsString("key === 'confirm_password' ? sharedBlock(candidate)?.input || null : null", $script);
        $this->assertStringContainsString('if (wrongPassword) wrongPassword.box.before(summary);', $script);
        // With the password in the same POST the row action goes through at once.
        $this->withSession(['confirmed_at' => time() - 301])->post($this->base.'/members', ['form' => 'row:'.$dana->id, 'name' => 'Dana', 'email' => 'dana@example.test', 'role' => 'editor', 'confirm_password' => self::PASSWORD])
            ->assertRedirect($this->base.'/members');
        $this->assertSame('editor', DB::table('members')->where('user_id', $dana->id)->value('role'));
    }

    public function test_confirm_password_attempts_are_rate_limited(): void
    {
        $this->signIn('owner', time() - 301);
        for ($i = 0; $i < 10; $i++) {
            $this->post($this->base.'/projects', ['name' => 'shop', 'confirm_password' => 'wrong-password'])->assertStatus(303)->assertSessionHas('form_errors.confirm_password');
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
        // data-unfilled: app.js then does not hold the action again after a wrong password, it sends the focus to the emptied field.
        $this->get($this->base.'/agents')->assertOk()->assertSee('Secret fields are not refilled.')->assertSee('}" data-unfilled >', false);
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString("const unfilled = summary.hasAttribute('data-unfilled');", $script);
        $this->assertStringContainsString('.confirm-inline.shared>.field-error{margin-top:4px;margin-bottom:0}', (string) file_get_contents(public_path('app.css')));
        // The script: "Hide values" conceals at once, the Reveal toggle of an empty secret input is off, and a submit
        // button is looked up through form.elements (it may stand outside its form).
        foreach (["hideNow.forEach(button => { button.hidden = false; button.addEventListener('click', () => conceal(true)); });", 'show.disabled = empty;', "const submitters = form => [...form.elements].filter(el => el.matches('button:not([type=button])'));"] as $line) {
            $this->assertStringContainsString($line, $script);
        }
        $this->assertStringNotContainsString("form.querySelector('button:not([type=button])')", $script);
        $this->assertStringContainsString('<button type="button" data-hide-values hidden>Hide values</button>', (string) file_get_contents(resource_path('views/entry.blade.php')));
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
            ->assertSee('<span class="event-name">Agent requested <code>shop.admin-login</code></span> <small class="nobreak event-code">request.open</small>', false)
            ->assertSee('<span class="event-name">Agent request</span>', false)->assertSee('<span class="event-name">Saved new version</span>', false)
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

    public function test_pause_toggle_titles_labelled_tables_and_copy(): void
    {
        $view = fn (string $name) => (string) file_get_contents(resource_path('views/'.$name.'.blade.php'));
        $css = (string) file_get_contents(public_path('app.css'));
        $js = (string) file_get_contents(public_path('app.js'));
        $this->signIn('owner');
        $this->waitingRequest();
        // "Pause automatic checks": a quiet aria-pressed toggle after "Check now", shown by script only; the status comes last.
        $this->get($this->base.'/requests')->assertOk()
            ->assertSee('<title>Requests · Main space · Vault</title>', false)
            ->assertSeeInOrder(['data-refresh-now>Check now<span class="sr-only"> for requests</span></a> <button type="button" class="quiet" data-refresh-pause aria-pressed="false" hidden>Pause automatic checks</button> <span class="note refresh-status" data-refresh-status'], false)
            ->assertSee('<h2 id="waiting" data-refresh="waiting-title">', false);
        $this->assertStringContainsString('<table class="board compact-board requests{{ empty($hideEntry) ? \'\' : \' no-entry\' }}" aria-labelledby="{{ $id }}"', $view('operations'));
        foreach (["const pauseKey = 'vault.requests.paused';", 'if (!manual && paused) return;', "'Paused \\u00B7 last checked ' + last + ' UTC'", "'Resume automatic checks' : 'Pause automatic checks'",
            "button.setAttribute('aria-pressed', String(paused));", "try { paused = sessionStorage.getItem(pauseKey) === '1'; } catch {}",
            "'Request ' + held[0] + ' will update when you leave this row'"] as $line) {
            $this->assertStringContainsString($line, $js);
        }
        $this->assertStringNotContainsString('The part you are working in was left as it is', $js);
        // Titles name the workspace on its pages, and only there.
        $this->get($this->base.'/grants')->assertOk()->assertSee('<title>Grants · Main space · Vault</title>', false)->assertSee('id="tab-current" aria-current="page"', false);
        $this->get('/account')->assertOk()->assertSee('<title>Account · Vault</title>', false);
        // Every board is named by its heading.
        $this->assertStringContainsString('<table class="board compact-board grants" aria-labelledby="tab-current">', $view('grants'));
        $this->assertStringContainsString('<table class="board compact-board identities" aria-labelledby="tab-current">', $view('agents'));
        $this->assertStringContainsString('<table class="board compact-board members" aria-labelledby="page-title">', $view('members'));
        $this->assertStringContainsString('<table class="board compact-board versions" aria-labelledby="versions">', $view('entry'));
        $this->assertStringContainsString('<table class="board compact-board access" aria-labelledby="access">', $view('entry'));
        $this->assertStringContainsString("'labelledby' => 'activity'", $view('entry'));
        $this->assertStringContainsString("'labelledby' => 'page-title'", $view('audit'));
        // Example placeholders start with "e.g. "; a format (YYYY-MM-DD) and an instruction are not examples.
        foreach (['entry-form', 'workspace-new', 'structure', 'types', 'agents'] as $name) {
            preg_match_all('/placeholder="([^"]*)"/', $view($name), $found);
            foreach ($found[1] as $placeholder) {
                $this->assertTrue(str_starts_with($placeholder, 'e.g. ') || in_array($placeholder, ['YYYY-MM-DD', 'Leave blank to keep'], true), $name.': '.$placeholder);
            }
        }
        $this->assertStringNotContainsString('placeholder="Field label"', $view('types'));
        // Confirm prompts carry no quotation marks.
        foreach (['types', 'structure', 'members', 'agents', 'grants', 'entry'] as $name) {
            preg_match_all('/data-confirm="([^"]*)"/', $view($name), $found);
            foreach ($found[1] as $prompt) {
                $this->assertDoesNotMatchRegularExpression('/[“”‘’]|&quot;/u', $prompt, $name);
            }
        }
        $this->assertStringContainsString('<option value="">Choose an environment</option>', $view('entry-form'));
        $this->assertStringContainsString('every entry is checked before any secret', $view('import'));
        $this->assertStringContainsString('<tr><td data-label="Environment"><input name="env_name[{{ $i }}]"', $view('structure'));
        $this->assertStringContainsString("@if(\$e->state === 'archived')<small><span class=\"chip muted\">archived</span></small>@elseif(\$due)<small><span class=\"chip warn\">rotation due</span>", $view('catalog'));
        // Validation names fields by their visible labels.
        $this->assertSame('Require owner approval for every run', trans('validation.attributes.approval'));
        $this->assertSame('6-digit code', trans('validation.attributes.code'));
        $this->assertSame('Type DELETE to destroy', trans('validation.attributes.confirmation'));
        foreach (['.field>.hint{grid-column:-2/-1;color:var(--faint);font-size:12px}', 'p.fold-foot{display:block;max-width:none}', '.stat .stat-clear{display:block;', 'text-transform:none;letter-spacing:0;font-size:13px}',
            '.form-foot .controls:has(>[data-add-row]){flex-wrap:nowrap}', 'button.quiet[aria-pressed=true]{'] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
    }

    public function test_one_grant_card_shared_request_columns_and_small_copy(): void
    {
        $view = fn (string $name) => (string) file_get_contents(resource_path('views/'.$name.'.blade.php'));
        $css = (string) file_get_contents(public_path('app.css'));
        $js = (string) file_get_contents(public_path('app.js'));
        // One grant card on phones: Grants and an entry's Agent access use the same cells and the same rules; the
        // state chip is at the top right, out of the flow, as on Identities.
        foreach (['grants' => $view('grants'), 'access' => $view('entry')] as $board => $source) {
            foreach (['<td class="who" data-label="Agent">', '<td class="action" data-label="Action">', '<td class="approval" data-label="Approval">', '<td class="state" data-label="State">'] as $cell) {
                $this->assertStringContainsString($cell, $source, $board.' '.$cell);
            }
            foreach (['tr{position:relative;align-items:center}', ' td.who{order:0;flex:0 1 auto;max-width:calc(100% - 84px)}', ' td.action{order:0;flex:0 1 auto;max-width:calc(100% - 84px);margin-right:84px}',
                ' td.state{position:absolute;top:10px;right:12px}', ' td.approval{order:2;flex-basis:100%}'] as $rule) {
                $this->assertStringContainsString('.board.'.$board.($rule[0] === ' ' ? '' : ' ').$rule, $css, $board.$rule);
            }
        }
        // Requests: the two boards share the widths of their leading columns.
        $this->assertStringContainsString('<th scope="col" class="c-who">Agent</th><th scope="col" class="c-action">Action</th>@empty($hideEntry)<th scope="col" class="c-entry">Entry</th>@endempty<th scope="col" class="c-state">State</th><th scope="col" class="c-when">Requested (UTC)</th>', $view('operations'));
        foreach (['c-who{width:123px}', 'c-action{width:144px}', 'c-entry{width:193px}', 'c-state{width:200px}', 'c-when{width:152px}'] as $rule) {
            $this->assertStringContainsString('.board.requests th.'.$rule, $css);
        }
        // Without the Entry column (an entry page) Agent and Action share its width: 176 + 284 = 123 + 144 + 193,
        // so State starts where it does on Requests.
        $this->assertStringContainsString('.board.requests.no-entry th.c-who{width:176px}', $css);
        $this->assertStringContainsString('.board.requests.no-entry th.c-action{width:284px}', $css);
        // Grants: the widths with a column of their own leave the entry 257 px of the 1100; the Revoke column is its button.
        foreach ([1 => 163, 2 => 144, 4 => 162, 5 => 200, 6 => 82, 7 => 92] as $n => $width) {
            $this->assertStringContainsString('.board.grants th:nth-child('.$n.'){width:'.$width.'px}', $css);
        }
        // Long data never moves a column or pushes the last one out of the board: Requests, Grants and an entry's
        // Agent access are fixed-layout tables, and agent names and action codes wrap (no .nobreak on them).
        foreach (['requests', 'grants', 'access'] as $board) {
            $this->assertStringContainsString('.board.'.$board.'{table-layout:fixed}', $css);
            $this->assertStringContainsString('.board.'.$board.' td{overflow-wrap:anywhere}', $css);
        }
        $this->assertStringContainsString('.board td:is(.who,.action,.name){overflow-wrap:anywhere}', $css);
        foreach (['operations', 'grants', 'entry'] as $name) {
            $this->assertStringNotContainsString('<b class="nobreak">', $view($name), $name);
            $this->assertStringContainsString('<td class="who" data-label="Agent"><b>', $view($name), $name);
        }
        // Grants: the first cell keeps the board's left padding, like every other board.
        $this->assertStringNotContainsString('.board.grants :is(th,td){padding-inline:8px}', $css);
        // A separator belongs to what comes before it, so a wrapped card line never starts with one.
        $this->assertStringContainsString('<span class="k" aria-hidden="true">&nbsp;·</span>', $view('operations'));
        $this->assertStringContainsString('<span class="k" aria-hidden="true">&nbsp;·</span>', $view('grants'));
        $this->assertStringContainsString('<span class="k" aria-hidden="true">&nbsp;·</span></td>', $view('catalog'));
        foreach (['operations', 'grants', 'catalog'] as $name) {
            $this->assertStringNotContainsString('<span class="k">· </span>', $view($name), $name);
        }
        $this->assertStringNotContainsString('.board.requests .left::before', $css);
        // The header is one row on wide screens; the account name is capped and cut with an ellipsis.
        $this->assertStringContainsString('.top{flex-wrap:nowrap}', $css);
        $this->assertStringContainsString('.top>details.menu.account{min-width:0;max-width:16em}', $css);
        // Entries: the open filter bar is one row, its selects capped at 12em.
        $this->assertStringContainsString('form.catalog-filters>details.filters[open] .toolbar select{width:100%;min-width:0;max-width:12em}', $css);
        // "All environments" is never cut: that select keeps its width and the others give way.
        $this->assertStringContainsString('form.catalog-filters>details.filters[open] .toolbar select[name=environment]{width:13em;max-width:none}', $css);
        // Setup, wide screens: a project's count and Edit are one group that does not wrap.
        $this->assertStringContainsString('.fold-tail{display:flex;flex:none;align-items:center;gap:16px;white-space:nowrap}', $css);
        // The confirm page, wide screens: Cancel stays beside the solid button, whose label wraps.
        $this->assertStringContainsString('.form-foot:has(.action-name) .controls{flex-wrap:nowrap;margin-left:auto}', $css);
        // The open workspace list shows a name whole.
        $this->assertStringContainsString('.menu-list .menu-name{white-space:normal;overflow:visible;overflow-wrap:anywhere}', $css);
        // Entries: four equal stat columns on desktop.
        $this->assertStringContainsString('@media (min-width:901px){.stats.entry-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))', $css);
        // A viewer has no Secret values section.
        $this->assertStringContainsString("@if(!\$isEditor)\n<p class=\"note\">Viewers see entry details and version history, not values.</p>\n@else\n<h2 id=\"values\">Secret values</h2>\n@if(!isset(\$secret) && !\$canEdit)", $view('entry'));
        // Grants: the add form describes itself for the held button.
        $grants = $view('grants');
        foreach (['<template data-held-summary><b class="name" data-held-value="machine_id"></b> may run <code data-held-value="action"></code>', 'data-held-value="entry_id" data-held-attr="entry"', 'data-held-sign="entry_id"',
            'valid for <span data-held-value="days" data-held-unit="day"></span>', 'data-held-check="approval" data-on="asks every time" data-off="runs without asking"', 'Require owner approval for every run',
            'data-entry="{{ $e->name }}" data-project="{{ $e->project }}" data-env="{{ $e->environment }}"'] as $part) {
            $this->assertStringContainsString($part, $grants);
        }
        $this->assertStringNotContainsString('Ask me before every run', $grants);
        $this->assertStringContainsString("const own = form?.querySelector(':scope > template[data-held-summary]');", $js);
        // Small copy and behaviour.
        $this->assertStringContainsString("name => 'Leave this page: ' + name", $js);
        $this->assertStringContainsString("el.matches('input[type=password], input[name=code]')", $js);
        $this->assertStringContainsString("document.querySelectorAll('a[data-focus]')", $js);
        $this->assertStringContainsString('<div class="controls" data-phone-first=".action-name"><a class="btn" href="{{ $return }}">Cancel</a>', $view('confirm'));
        $this->assertSame(2, substr_count($view('confirm'), 'data-phone-first=".action-name"'));
        $this->assertStringContainsString('.form-foot .controls>.action-name{order:-1}', $css);
        $this->assertStringContainsString('Viewers see entry details.', $view('members'));
        $this->assertStringNotContainsString('metadata', $view('members'));
        $this->assertSame(2, substr_count($view('types'), '>+ Add a field</button>'));
        $this->assertStringContainsString('aria-label="Add an environment to the template"><span>+ Add<span class="wide-only"> an environment</span></span></button>', $view('structure'));
        $this->assertStringContainsString('placeholder="e.g. qa" aria-label="Additional environment name"', $view('structure'));
        $this->assertSame(2, substr_count($view('account'), '<span class="with-button"><input id="new-password'));
        // Straight apostrophes in every text of the UI.
        foreach (glob(resource_path('views/*.blade.php')) as $file) {
            $this->assertStringNotContainsString('’', (string) file_get_contents($file), basename($file));
        }
        $this->assertStringNotContainsString('’', $js);
    }

    public function test_requests_refresh_in_place_regions_and_wording(): void
    {
        // Setup mode: the activation code comes first.
        $this->get('/login')->assertOk()->assertSeeInOrder(['<span>Activation code</span>', '<span>Name</span>', '<span>Email</span>', '<span>Password</span>'], false)
            // The note under the activation code is that field's description.
            ->assertSeeInOrder(['<div class="field"><label for="bootstrap-code">', 'id="bootstrap-code"', 'aria-describedby="bootstrap-code-hint"><small class="hint" id="bootstrap-code-hint">', '</small></div>'], false)
            // The new password can be checked before it is sent: app.js adds Reveal/Hide to a password input in .with-button.
            ->assertSee('<div class="field"><label for="setup-password"><span>Password</span></label><span class="with-button"><input id="setup-password" type="password" name="password" required minlength="14"', false);
        $this->signIn('owner');
        // The request sections sit in regions app.js replaces in place. The button and the time of the last check sit beside the
        // waiting heading, outside every region; without script the button is a plain link.
        $this->get($this->base.'/requests')->assertOk()
            ->assertSeeInOrder(['<div class="section-head">', '<h2 id="waiting" data-refresh="waiting-title">',
                '<a class="btn" href="'.$this->base.'/requests" data-refresh-now>Check now<span class="sr-only"> for requests</span></a>', 'data-refresh-status', '>Checked ', ' UTC</span>',
                '<div data-refresh="waiting" data-refresh-url="'.$this->base.'/requests">', '<div data-refresh="history" data-refresh-url="'.$this->base.'/requests">', '<h2 id="history">'], false)
            ->assertDontSee('Refresh requests')->assertDontSee('automatic updates');
        // Polling does not stop while the queue is empty; a hidden tab pauses. An expired row keeps the focus on its status.
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString('if (running || (!manual && (document.hidden || Date.now() < due || Date.now() - used > 900000))) return;', $script);
        $this->assertStringContainsString('chip.focus({ preventScroll: true })', $script);
        $this->assertStringNotContainsString("getElementById('content')?.focus", $script);
        // A board is merged row by row: only the row in use is kept, its siblings change around it, and the heading counts the rows
        // shown. A round that failed or left a stale row behind does not advance the time.
        $this->assertStringContainsString("const kept = [...region.querySelectorAll('table.requests tbody tr[data-request]')]", $script);
        $this->assertStringContainsString('body.insertBefore(document.adoptNode(row), kept[at] || null);', $script);
        // The status keeps to one verb: "Checked HH:MM UTC", "Check delayed · last checked HH:MM UTC".
        $this->assertStringContainsString("const delayed = (why = 'Check delayed') => why + ' \\u00B7 last checked ' + last + ' UTC';", $script);
        $this->assertStringNotContainsString('Queue checked', $script);
        $this->assertStringNotContainsString('Last updated', $script);
        $this->assertStringContainsString('else status(delayed());', $script);
        $this->assertStringNotContainsString('if (inUse(region)) { skipped = true; return; }', $script);
        $this->get($this->base.'/structure')->assertOk()->assertDontSee('<h2>Projects', false)->assertDontSee('Environments by project')->assertDontSee('<h2>Environments</h2>', false)
            // No h2 repeats the active tab: the project cards follow the tabs, and the two forms keep their h2.
            ->assertSeeInOrder(['</nav>', '<p class="note">A project groups entries by environment.', 'id="confirm-setup"', '<div class="deck project-list">', '<h2 id="add-project">Add a project</h2>', 'id="confirm-add-project"  data-primary="confirm-setup"', '<h2 id="template">Environment template</h2>', 'id="confirm-template"  data-primary="confirm-setup"'], false)
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
            // Sign out is no section: one plain button in the head row beside the h1.
            ->assertSee('<div class="head capped"><h1 id="page-title">Account</h1><form method="post" action="/logout">', false)->assertDontSee('<h2>Sign out</h2>', false)
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
        // The status code of an error page is a dim line under the h1, not inside it; the callout is the one red mark.
        $this->get('/nothing-here')->assertNotFound()->assertSeeInOrder(['<h1>Not found</h1>', '<p class="error-status">Error 404</p>', '<div class="callout bad">'], false)
            ->assertDontSee('chip bad', false);
        // 409 and 422: with a return path the button leads back to the page; without one it and the instruction say "entries".
        $this->view('error', ['status' => 422, 'shell' => true, 'returnPath' => '/workspaces/w/types'])
            ->assertSeeInOrder(['Go back to the previous page to correct the form.', '<a class="btn" href="/workspaces/w/types">Back to previous page</a>'], false);
        $this->view('error', ['status' => 409, 'shell' => true, 'returnPath' => null])
            ->assertSeeInOrder(['It changed meanwhile, or it is still in use. Nothing was changed.', 'Go back to entries to see the current state, then try again.', '>Back to entries</a>'], false)
            ->assertDontSee('previous page')->assertDontSee('Reload');
        $this->view('error', ['status' => 409, 'shell' => true, 'returnPath' => '/workspaces/w/types'])
            ->assertSeeInOrder(['Go back to the previous page to see the current state, then try again.', '>Back to previous page</a>'], false)->assertDontSee('Reload');
        $this->view('error', ['status' => 404, 'shell' => true, 'returnPath' => '/x'])->assertSee('>Back to entries</a>', false);
        // The token page asks before Back, reload or closing the tab while the token is unsaved, and stops asking
        // after a copy, "I've saved it" or a departure confirmed in the page. The header's focus order follows its rows on phones.
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString("addEventListener('beforeunload', beforeLeave);", $script);
        $this->assertStringContainsString("const lift = () => { guarding = false; allowLeave(); only(null);", $script);
        $this->assertSame(2, substr_count($script, 'if (mine.armed()) { if (mine.tooSoon()) event.preventDefault(); else allowLeave(); return; }'));
        $this->assertStringContainsString('if (first) mainNav.before(accountMenu); else topBar.append(accountMenu);', $script);
        $this->get('/')->assertOk()->assertSeeInOrder(['<details class="menu space">', '<nav class="nav" aria-label="Main"', '<details class="menu account">'], false);
        $css = (string) file_get_contents(public_path('app.css'));
        $this->assertStringContainsString('main>details.filters[data-wide-open] .toolbar>button:last-child{margin-left:auto}', $css);
        $this->assertStringContainsString('.confirm-inline.is-deferred:not(.is-quiet){padding-top:12px;border-top:1px dashed var(--rule)}', $css);
        $this->assertStringContainsString('.confirm-step .mark.armed{', $css);
        $this->assertStringNotContainsString('reading-flow:', $css);
        // Small copy: the Identities head, a request without an owner decision, fields that left the type, the template rows.
        $this->assertStringContainsString('<th scope="col">End date (UTC)</th>', (string) file_get_contents(resource_path('views/agents.blade.php')));
        $this->view('outcome', ['outcome' => 'revoked'])->assertSee('>cancelled</span>', false);
        $this->assertStringContainsString("{{ \$n }} {{ \$what }} {{ \$n === 1 ? 'field is' : 'fields are' }} no longer in the type", (string) file_get_contents(resource_path('views/entry.blade.php')));
        $this->assertStringNotContainsString('Read-only summary', (string) file_get_contents(resource_path('views/entry.blade.php')));
        $this->get($this->base.'/structure')->assertOk()->assertSee('<td data-label="Environment">', false)->assertSee('aria-label="Additional environment name"', false);
        $this->assertStringContainsString('<button class="go" data-held-name="Add grant: " data-held-field="action">Add grant</button>', (string) file_get_contents(resource_path('views/grants.blade.php')));
        $this->assertStringContainsString('<span class="nobreak">{{ $time($o->expires_at) }} <small class="k">UTC</small></span> <span class="left" hidden>', (string) file_get_contents(resource_path('views/operations.blade.php')));
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
        $this->withSession(['confirmed_at' => time()])->get('/account')->assertOk()->assertSeeInOrder(['<h2 id="security" class="capped">Security</h2>', '<h3>Change sign-in password</h3>'], false)
            ->assertDontSee('password-fold', false);
        // While a confirmation is needed the page shows one password field at rest: changing the password is a closed fold.
        $this->withSession(['confirmed_at' => time() - 301])->get('/account')->assertOk()
            ->assertSeeInOrder(['<h2 id="security" class="capped">Security</h2>', '<form class="action" id="confirm" method="post" action="/confirm">', '<details class="fold password-fold">',
                // The toggle reads "Edit" like every other fold; the section headings end where the cards end (.capped).
                '<summary><span class="fold-name legend">Change sign-in password</span><span class="fold-act"><span class="when-closed">Edit</span><span class="when-open">Close</span></span></summary>',
                '<form method="post" action="/password">', '</details>', '<h2 id="workspaces" class="capped">Workspaces</h2>'], false);
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
        $this->assertStringContainsString('<span class="nobreak"><small class="k keep">Revoked</small> 2026-11-20 10:15 <small class="k">UTC</small></span>', $revoked);
        $this->assertStringContainsString('<small class="wrap">agent revoked</small>', $revoked);
        $this->assertStringNotContainsString('Grant expiry', $revoked);
        $soon = now()->addDays(2)->format('Y-m-d H:i');
        $later = now()->addDays(9)->format('Y-m-d H:i');
        $active = (string) $this->view('end-date', ['revoked' => null, 'ends' => $soon.':00', 'reason' => 'agent expires first', 'own' => $later.':00']);
        $this->assertStringContainsString('<span class="nobreak"><small class="k">Expires</small> '.$soon.' <small class="k">UTC</small></span>', $active);
        $this->assertStringContainsString('<small class="wrap">Grant expiry: <span class="nobreak">'.$later.' UTC</span></small>', $active);
        $this->assertStringContainsString('<span class="nobreak"><small class="k keep">Expired</small> 2020-01-01 00:00 ', (string) $this->view('end-date', ['revoked' => null, 'ends' => '2020-01-01 00:00:00']));
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
            ->assertSee('<span data-when="deferred" hidden>Password confirmation expired. <a class="more-link" href="#confirm-decide-password" data-confirm-goto="confirm-decide">Go to the password field <span aria-hidden="true">↑</span></a></span>', false)
            ->assertDontSee('enter it above');
        // Nothing waits: the Reveal block is the one that says it.
        $alone = $this->view('confirm-inline', ['confirmedUntil' => time() + 200, 'verb' => 'reveal', 'shared' => 'confirm-reveal', 'primary' => 'confirm-decide', 'primaryShown' => false]);
        $alone->assertSee('class="confirm-inline shared" id="confirm-reveal"', false)->assertSee('<span data-when="valid" >Password confirmed until', false);
        $script = (string) file_get_contents(public_path('app.js'));
        // A deferred block's forms use the primary field; a block whose own field is in use is not deferred.
        $this->assertStringContainsString('if (first && !first.box.hidden && state.deferred()) state = first;', $script);
        $this->assertStringContainsString("const busy = !input.disabled && !input.closest('[hidden]') && (document.activeElement === input || input.value !== '');", $script);
        // While it points to the one field, its own card's solid button is plain.
        $this->assertStringContainsString('plainOwn(deferred && expired);', $script);
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
        // Nothing is revealed: no "Hide values" button. (With values on screen it is the first control of the foot.)
        $this->get('/entries/'.$entry)->assertOk()->assertDontSee('class="foot"', false)->assertDontSee('data-hide-values', false)
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
        // The detour is a closed disclosure: "Missing a type or field?" stays in view, the rest is behind it.
        // Setup opens in a new tab and says so; "Reload types" (shown by script) swaps the Type options and #type-fields only.
        // The sentence names the button, which only script shows: the page prints the wording for no script, and app.js swaps it.
        $page->assertSee('/types" target="_blank" rel="noopener">Edit entry types in a new tab</a>. <span data-script-text="After editing the entry type, return here and choose Reload types.">After editing the entry type, load this page again. What you typed here is not kept.</span></span>', false)
            ->assertSeeInOrder(['<details class="type-detour" data-reload-types-row><summary>Missing a type or field?</summary><div class="type-detour-body"><span class="note"><a class="more-link"', 'Edit entry types in a new tab</a>', '<button type="button" data-reload-types hidden>Reload types</button>', 'data-reload-status></span></div></details>'], false)
            ->assertSeeInOrder(['id="entry-type"', '<fieldset id="type-fields">', '<legend>Tags and rotation</legend>'], false)
            // Every required field of Details carries the same marker.
            ->assertSee('<label><span>Name <small aria-hidden="true">required</small></span><input name="name"', false)
            ->assertSee('<label><span>Project / environment <small aria-hidden="true">required</small></span><select name="environment_id" required>', false)
            ->assertSee('<label><span>Type <small aria-hidden="true">required</small></span><select name="type" id="entry-type" required>', false)
            // The alias hint is the field's description, not part of its name. Adding, the alias follows the name and is not required.
            ->assertSee('<div class="field"><label for="entry-alias"><span>Agent alias</span></label><input id="entry-alias"', false)
            ->assertSee('placeholder="e.g. shop-stage-admin" aria-describedby="entry-alias-hint" data-alias-from-name><small class="hint" id="entry-alias-hint">', false)
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

    public function test_update_form_reloads_its_fields_and_keeps_every_clear_box(): void
    {
        $this->signIn('owner');
        $project = (string) Str::uuid();
        $environment = (string) Str::uuid();
        $entry = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $this->workspace, 'name' => 'shop']);
        DB::table('environments')->insert(['id' => $environment, 'project_id' => $project, 'name' => 'stage']);
        DB::table('entries')->insert(['id' => $entry, 'environment_id' => $environment, 'name' => 'Deploy key', 'alias' => 'deploy-key', 'type' => 'ssh', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // The update form has the Setup detour too; its type is fixed, so the button reloads the fields.
        $this->get('/entries/new?workspace='.$this->workspace.'&entry='.$entry)->assertOk()
            ->assertSeeInOrder(['<details class="type-detour" data-reload-types-row><summary>Missing a field?</summary>', '/types#type-ssh" target="_blank" rel="noopener">Edit entry types in a new tab</a>', 'data-script-text="After editing the entry type, return here and choose Reload fields."', ' hidden>Reload fields</button>', 'data-reload-status></span></div></details>', '<fieldset id="type-fields">'], false)
            // Each "Clear the current … value" box has an id of its type and field, so a reload can give it back its state.
            ->assertSee('<input type="checkbox" id="clear-ssh-passphrase" name="clear_fields[]" value="passphrase" data-optional', false)
            ->assertDontSee('id="clear-ssh-private_key"', false);
        $script = (string) file_get_contents(public_path('app.js'));
        // Checked and unchecked boxes are restored by id; the status never says "kept" for what was not.
        $this->assertStringContainsString('boxes.set(el.id, el.checked);', $script);
        $this->assertStringContainsString('el.checked = boxes.get(el.id);', $script);
        $this->assertStringContainsString("'What you typed was kept, and the values marked to clear are still marked.'", $script);
        $this->assertStringContainsString('is no longer marked: its field is gone or is now required.', $script);
        $this->assertStringContainsString("'The rest of the form is unchanged.'", $script);
        $this->assertStringNotContainsString("if (el.type === 'checkbox') el.checked = true; else el.value = kept;", $script);
        // A typed value without a field after the reload is asked about before anything is replaced: the fields are
        // named by their labels, and the old controls stay until "Discard and reload".
        $this->assertStringContainsString('if (labels.length) ask(nextType, nextRows, labels); else apply(nextType, nextRows, []);', $script);
        $this->assertStringContainsString("discard.textContent = 'Discard and reload';", $script);
        $this->assertStringContainsString("'Reload cancelled. The ' + what + ' and what you typed are as they were.'", $script);
        $this->assertStringContainsString("row?.querySelectorAll('[data-script-text]')", $script);
        // An action held by a block that becomes deferred is released.
        $this->assertStringContainsString('if (deferred) release?.();', $script);
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

    public function test_refresh_row_order_edges_and_wording(): void
    {
        $view = fn (string $name) => (string) file_get_contents(resource_path('views/'.$name.'.blade.php'));
        $css = (string) file_get_contents(public_path('app.css'));
        $js = (string) file_get_contents(public_path('app.js'));
        // The refresh row reads left to right in the order of the document; nothing is reversed.
        $this->assertStringNotContainsString('row-reverse', $css);
        $this->assertStringContainsString('button.quiet{background:none;color:var(--dim);font-weight:600}', $css);
        // One verb family: check.
        foreach (['Refresh requests', 'automatic updates', 'Automatic updates'] as $old) {
            $this->assertStringNotContainsString($old, $js);
            $this->assertStringNotContainsString($old, $view('operations'));
        }
        $this->assertStringContainsString("say('Automatic checks paused. Check now still checks once.');", $js);
        // Drawers and the quick jump link are controls: they carry the control edge.
        $this->assertStringContainsString('@media (max-width:900px){details.filters{border-color:var(--edge)}', $css);
        $this->assertStringContainsString('details.jump{background:var(--panel);border:1px solid var(--edge);border-radius:4px}', $css);
        $this->assertMatchesRegularExpression('/a\.jump-quick:hover\{[^}]*border:1px solid var\(--edge\)/', $css);
        // Account: one column at the card width in both states. Entries: fixed columns, named by the h1.
        $this->assertStringContainsString('<div class="deck wide capped">', $view('account'));
        $this->assertStringContainsString('.board.entries{table-layout:fixed}', $css);
        $this->assertStringContainsString('<table class="board compact-board entries" aria-labelledby="page-title">', $view('catalog'));
        // An inactive grant speaks in the past tense.
        foreach (['grants', 'entry'] as $name) {
            $this->assertStringContainsString("'previously asked every time' : 'previously ran without asking'", $view($name));
        }
        // The confirm page never names the workspace in its title, at whatever address it is rendered.
        $this->signIn('owner', time() - 3600);
        $this->post($this->base.'/projects', ['name' => 'shop', 'back' => 'structure'])->assertForbidden()
            ->assertSee('<title>Password confirmation · Vault</title>', false);
        $this->get($this->base.'/structure')->assertOk()->assertSee('<title>Projects · Main space · Vault</title>', false);
        // The entry form says what to do about a missing type in words on the page, not in a title.
        $this->assertStringNotContainsString('title="After saving', $view('entry-form'));
        $this->assertSame(2, substr_count($view('entry-form'), 'After editing the entry type, load this page again. What you typed here is not kept.</span>'));
        $this->assertStringNotContainsString('then reload here', $view('entry-form'));
        foreach (['entry', 'entry-form'] as $name) {
            $this->assertDoesNotMatchRegularExpression('/[“”‘’]/u', $view($name), $name);
        }
        $this->assertStringContainsString('<span class="muted nobreak">— the name agents see</span>', $view('entry'));
        $structure = (string) file_get_contents(app_path('Vault/Structure.php'));
        $this->assertStringNotContainsString('Remove all entries', $structure);
        $this->assertStringContainsString('Archive and destroy all entries in this project before removing it.', $structure);
    }

    public function test_expiring_soon_width_budget_and_round_19_nits(): void
    {
        // An active agent or grant that ends within 3 days says so; one that is revoked, expired or further off does not.
        $soon = (string) $this->view('end-date', ['revoked' => null, 'ends' => now()->addDays(2)->format('Y-m-d H:i:s')]);
        $this->assertStringContainsString('<small class="k">UTC</small></span> <span class="chip warn">expires soon</span>', $soon);
        foreach ([['revoked' => null, 'ends' => now()->addDays(4)->format('Y-m-d H:i:s')], ['revoked' => null, 'ends' => now()->subDay()->format('Y-m-d H:i:s')],
            ['revoked' => now()->subHour()->format('Y-m-d H:i:s'), 'ends' => now()->addDay()->format('Y-m-d H:i:s')]] as $data) {
            $this->assertStringNotContainsString('expires soon', (string) $this->view('end-date', $data));
        }
        $this->assertTrue(\App\Vault\Access::endsSoon(null, now()->addDays(3)->subMinute()));
        $this->assertFalse(\App\Vault\Access::endsSoon(null, now()->addDays(3)->addMinute()));
        $this->assertFalse(\App\Vault\Access::endsSoon(null, now()->subMinute()));
        $this->assertFalse(\App\Vault\Access::endsSoon(now(), now()->addDay()));
        // The row of such an agent or grant carries the waiting stripe on all three boards.
        foreach (['agents', 'grants', 'entry'] as $view) {
            $this->assertStringContainsString("\\App\\Vault\\Access::endsSoon(", (string) file_get_contents(resource_path('views/'.$view.'.blade.php')));
        }
        $this->signIn('owner');
        $machine = (string) Str::uuid();
        DB::table('machines')->insert(['id' => $machine, 'workspace_id' => $this->workspace, 'name' => 'short-lived', 'token_hash' => hash('sha256', $machine), 'expires_at' => now()->addDay()]);
        $this->get($this->base.'/agents')->assertOk()->assertSee('<tr id="agent-'.$machine.'" class="is-wait">', false)->assertSee('<span class="chip warn">expires soon</span>', false);
        // Environment signs: "staging" is amber like "stage"; anything else is plain.
        $this->assertStringContainsString('class="sign stage"', (string) $this->view('sign', ['name' => 'Staging-eu-west-1', 'production' => false]));
        $this->assertStringContainsString('class="sign stage"', (string) $this->view('sign', ['name' => 'stage', 'production' => false]));
        $this->assertStringContainsString('class="sign "', (string) $this->view('sign', ['name' => 'qa', 'production' => false]));
        $this->assertStringContainsString('class="sign prod"', (string) $this->view('sign', ['name' => 'staging', 'production' => true]));
        // A waiting request's deadline stands in its Decision cell, not under Requested.
        $operations = (string) file_get_contents(resource_path('views/operations.blade.php'));
        $this->assertMatchesRegularExpression('/<td class="decision" data-label="Decision">\s*\{\{--.*?--\}\}\s*<span class="line expires">/s', $operations);
        $this->assertStringNotContainsString('@unless($finished)<span class="line expires">', $operations);
        $css = (string) file_get_contents(public_path('app.css'));
        $this->assertStringContainsString('.board.grants th:nth-child(7){width:92px}', $css);
        $this->assertStringContainsString('.board.requests th.c-when{width:152px}', $css);
        $script = (string) file_get_contents(public_path('app.js'));
        $this->assertStringContainsString('confirmSteps.get(form)?.disarm(false);', $script);
        $this->assertStringNotContainsString("removeAttribute('aria-describedby');\n    if (none", $script);
        // The Grants empty state under the filter bar is as wide as the bar; the one that replaces the form is capped.
        $grants = (string) file_get_contents(resource_path('views/grants.blade.php'));
        $this->assertStringContainsString("<div class=\"empty\">{{ \$filterCount ? 'No matching grants.' : 'No grants yet.' }}", $grants);
        $this->assertSame(1, substr_count($grants, '<div class="empty capped">'));
        // Activity: a row that went well is idle, so denied and failed stand out.
        $this->assertStringNotContainsString("=> 'is-ok'", (string) file_get_contents(resource_path('views/activity.blade.php')));
        $this->get('/account')->assertOk()->assertSee('<table class="facts" aria-labelledby="page-title">', false);
    }
}
