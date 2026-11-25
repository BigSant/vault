<?php

namespace Tests;

use App\Models\User;
use App\Vault\AccountRecovery;
use App\Vault\Catalog;
use App\Vault\Operations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OTPHP\TOTP;

final class VaultTest extends TestCase
{
    private string $temp;

    private array $backend = [];

    private User $owner;

    private string $workspace;

    private string $environment;

    private string $sentinel = 'synthetic-secret-never-in-agent-output';

    /** Runs once before the next backend secret read, to simulate a concurrent writer. */
    private ?\Closure $beforeRead = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate', ['--force' => true]);
        $this->temp = sys_get_temp_dir().'/vault-test-'.bin2hex(random_bytes(8));
        mkdir($this->temp, 0700);
        file_put_contents($this->temp.'/bao.json', '{"role_id":"test","secret_id":"test"}');
        file_put_contents($this->temp.'/broker.key', 'test-broker');
        file_put_contents($this->temp.'/bootstrap.code', 'test-bootstrap');
        file_put_contents($this->temp.'/connectors.json', json_encode(['actions' => ['test.http' => ['kind' => 'http', 'url' => 'https://example.invalid/status']]]));
        config(['vault.credentials' => $this->temp.'/bao.json', 'vault.broker_key' => $this->temp.'/broker.key',
            'vault.bootstrap_file' => $this->temp.'/bootstrap.code', 'vault.connectors' => $this->temp.'/connectors.json']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/auth/approle/login') {
                return Http::response(['auth' => ['client_token' => 'test']]);
            }
            if (str_starts_with($path, '/v1/secret/data/')) {
                if ($request->method() === 'POST') {
                    $current = count($this->backend[$path] ?? []);
                    if ($request['options']['cas'] !== $current) {
                        return Http::response([], 400);
                    }
                    $this->backend[$path][$current + 1] = $request['data'];

                    return Http::response(['data' => ['version' => $current + 1]]);
                }
                if ($hook = $this->beforeRead) {
                    $this->beforeRead = null;
                    $hook();
                }
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
                $version = (int) ($query['version'] ?? count($this->backend[$path] ?? []));

                return isset($this->backend[$path][$version]) ? Http::response(['data' => ['data' => $this->backend[$path][$version], 'metadata' => ['version' => $version]]]) : Http::response([], 404);
            }
            if (str_starts_with($path, '/operations')) {
                return Http::response(['state' => 'succeeded']);
            }

            return Http::response([], 503);
        });
        $this->owner = User::create(['id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('Synthetic-password-123!'), 'mfa_enabled' => true]);
        $this->workspace = (string) Str::uuid();
        $project = (string) Str::uuid();
        $this->environment = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $this->workspace, 'name' => 'Test']);
        DB::table('members')->insert(['workspace_id' => $this->workspace, 'user_id' => $this->owner->id, 'role' => 'owner']);
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $this->workspace, 'name' => 'Test project']);
        DB::table('environments')->insert(['id' => $this->environment, 'project_id' => $project, 'name' => 'stage']);
        $this->actingAs($this->owner)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time(), 'auth_revision' => $this->owner->credentialRevision()]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp.'/*') as $f) {
            unlink($f);
        }rmdir($this->temp);
        parent::tearDown();
    }

    private function entry(): string
    {
        return app(Catalog::class)->create(['environment_id' => $this->environment, 'name' => 'Private name', 'alias' => 'safe-alias', 'type' => 'api_key', 'secret' => json_encode(['token' => $this->sentinel])]);
    }

    private function machine(string $entry, bool $approval = false): array
    {
        $id = (string) Str::uuid();
        $token = str_repeat('b', 64);
        DB::table('machines')->insert(['id' => $id, 'workspace_id' => $this->workspace, 'name' => 'ai-os', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $grant = (string) Str::uuid();
        DB::table('grants')->insert(['id' => $grant, 'machine_id' => $id, 'entry_id' => $entry, 'action' => 'test.http', 'approval' => $approval, 'expires_at' => now()->addHour()]);

        return [$id, $token, $grant];
    }

    public function test_workspace_navigation_enforces_roles(): void
    {
        $base = '/workspaces/'.$this->workspace;
        foreach (['owner', 'editor', 'viewer'] as $role) {
            DB::table('members')->where('workspace_id', $this->workspace)->update(['role' => $role]);
            foreach (['requests', 'agents', 'grants', 'members'] as $page) {
                $this->get($base.'/'.$page)->assertStatus($role === 'owner' ? 200 : 404);
            }
            if ($role === 'owner') {
                $this->get($base.'/settings')->assertRedirect($base.'/requests');
            } else {
                $this->get($base.'/settings')->assertNotFound();
            }
        }
        $this->get($base.'/import')->assertNotFound();
        $this->post($base.'/import')->assertNotFound();
    }

    public function test_owner_and_editor_can_open_import(): void
    {
        $url = '/workspaces/'.$this->workspace.'/import';
        $this->get($url)->assertOk()->assertViewHas('environments', fn ($rows) => $rows->contains('id', $this->environment))
            ->assertViewHas('types', fn ($rows) => $rows->contains('key', 'api_key'));
        DB::table('members')->where('workspace_id', $this->workspace)->update(['role' => 'editor']);
        $this->get($url)->assertOk();
        $row = ['environment_id' => $this->environment, 'name' => 'Editor import', 'alias' => 'editor-import', 'type' => 'api_key', 'secret' => '{"token":"synthetic-editor-import"}'];
        $this->post($url, ['import' => json_encode([$row])])->assertRedirect();
        $this->assertTrue(DB::table('entries')->where('alias', 'editor-import')->exists());
    }

    public function test_old_waiting_requests_stay_visible_beyond_the_history_limit(): void
    {
        $entry = $this->entry();
        [$machine, , $grant] = $this->machine($entry);
        $rows = [];
        foreach (['pending', 'requires_user_action', ...array_fill(0, 51, 'succeeded')] as $index => $state) {
            $rows[] = ['id' => (string) Str::uuid(), 'entry_id' => $entry, 'machine_id' => $machine, 'grant_id' => $grant,
                'request_id' => 'request-'.$index, 'action' => 'test.http', 'version' => 1, 'state' => $state,
                'expires_at' => now()->addHours($index + 1), 'created_at' => now()->subMinutes(60 - $index)];
        }
        DB::table('operations')->insert($rows);
        $this->get('/workspaces/'.$this->workspace.'/requests')->assertOk()
            ->assertViewHas('waiting', fn ($waiting) => $waiting->pluck('id')->all() === [$rows[0]['id'], $rows[1]['id']])
            ->assertViewHas('history', fn ($history) => $history->count() === 50)
            ->assertSeeInOrder(['2 requests waiting for you', 'ai-os', 'test.http', 'Private name', 'stage', 'Allow once', 'needs MFA code', 'Continue sign-in', 'Recent requests'])
            ->assertDontSee($rows[2]['id']);
        $this->get('/entries/'.$entry)->assertOk()->assertViewHas('waitingCount', 2)
            ->assertViewHas('operations', fn ($ops) => $ops->take(2)->pluck('id')->all() === [$rows[0]['id'], $rows[1]['id']]);
    }

    public function test_secret_stays_in_backend_and_is_not_prefilled_or_listed(): void
    {
        $id = $this->entry();
        $this->get('/')->assertOk()->assertDontSee($this->sentinel);
        $this->get('/entries/'.$id)->assertOk()->assertDontSee($this->sentinel);
        $this->get('/entries/new?workspace='.$this->workspace.'&entry='.$id)->assertOk()->assertDontSee($this->sentinel);
        $this->assertStringNotContainsString($this->sentinel, json_encode(DB::table('entries')->get()));
        $this->assertStringNotContainsString($this->sentinel, json_encode(DB::table('audit')->get()));
        $this->post('/entries/'.$id.'/reveal')->assertOk()->assertSee($this->sentinel)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_reveal_requires_recent_confirmation_and_editor_membership(): void
    {
        $id = $this->entry();
        $this->withSession(['confirmed_at' => time() - 301])->post('/entries/'.$id.'/reveal')->assertForbidden();
        DB::table('members')->where('user_id', $this->owner->id)->update(['role' => 'viewer']);
        $this->withSession(['confirmed_at' => time()])->post('/entries/'.$id.'/reveal')->assertNotFound();
    }

    public function test_machine_catalog_and_execution_never_return_secret_or_private_name(): void
    {
        $id = $this->entry();
        [$machine,$token] = $this->machine($id);
        $this->withToken($token)->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('entries.0.alias', 'safe-alias')->assertDontSee('Private name')->assertDontSee($this->sentinel);
        $response = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $id, 'action' => 'test.http', 'request_id' => 'request-1234'])->assertOk();
        $op = $response->json('id');
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'succeeded')->assertDontSee($this->sentinel);
        $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $id, 'action' => 'test.http', 'request_id' => 'request-1234'])->assertJsonPath('id', $op);
        $this->assertSame(1, DB::table('operations')->count());
    }

    public function test_expired_revoked_and_foreign_grants_fail_closed(): void
    {
        $id = $this->entry();
        [$machine,$token,$grant] = $this->machine($id);
        DB::table('grants')->where('id', $grant)->update(['expires_at' => now()->subSecond()]);
        $this->withToken($token)->getJson('/api/v1/catalog')->assertExactJson(['entries' => [], 'next_page' => null]);
        $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $id, 'action' => 'test.http', 'request_id' => 'request-1234'])->assertNotFound();
        DB::table('grants')->where('id', $grant)->update(['expires_at' => now()->addHour()]);
        DB::table('machines')->where('id', $machine)->update(['revoked_at' => now()]);
        $this->withToken($token)->getJson('/api/v1/catalog')->assertUnauthorized();
    }

    public function test_approval_is_bound_to_operation_and_rotation_invalidates_it(): void
    {
        $id = $this->entry();
        [$machine,$token] = $this->machine($id, true);
        $op = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $id, 'action' => 'test.http', 'request_id' => 'request-1234'])->assertJsonPath('state', 'pending')->json('id');
        $this->post('/workspaces/'.$this->workspace.'/operations/'.$op.'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->assertNotNull(app(Operations::class)->authorized($op));
        app(Catalog::class)->update($id, ['environment_id' => $this->environment, 'name' => 'Private name', 'alias' => 'safe-alias', 'type' => 'api_key', 'secret' => json_encode(['token' => 'rotated-test-value'])]);
        $this->assertNull(app(Operations::class)->authorized($op));
        $this->assertSame('revoked', DB::table('operations')->where('id', $op)->value('state'));
        $this->assertSame($this->sentinel, app(Catalog::class)->reveal($id, 1)['token']);
    }

    public function test_cross_workspace_access_and_last_owner_removal_are_rejected(): void
    {
        $this->get('/workspaces/'.$this->workspace.'/members')->assertOk()->assertSeeText('you')->assertDontSeeText('Remove');
        $id = $this->entry();
        $other = User::create(['id' => (string) Str::uuid(), 'name' => 'Other', 'email' => 'other@example.test', 'password' => 'unused']);
        $this->actingAs($other)->withSession(['auth_revision' => $other->credentialRevision()])->get('/entries/'.$id)->assertNotFound();
        $this->actingAs($this->owner)->withSession(['auth_revision' => $this->owner->credentialRevision()])->post('/workspaces/'.$this->workspace.'/members', ['email' => $this->owner->email, 'name' => 'Owner', 'role' => 'remove'])->assertConflict();
    }

    public function test_activity_resolves_grant_subject_and_filters_by_group_and_actor(): void
    {
        $entry = $this->entry();
        [$machine, , $grant] = $this->machine($entry);
        app(\App\Vault\Access::class)->audit($this->workspace, 'grants.revoke', $grant, 'ok', 'machine:'.$machine);
        $url = '/workspaces/'.$this->workspace.'/audit';
        $this->get($url.'?group=grant&actor=machine:'.$machine)->assertOk()->assertSeeText('ai-os')
            ->assertSeeText('grant.revoke')->assertSeeText('Private name')->assertDontSeeText('entry.create')
            ->assertSee('href="/entries/'.$entry.'"', false)->assertDontSeeText('Page 1')->assertSeeText('Clear filters')->assertSee('compact-board activity');
        $this->get($url.'?group=entry&actor=machine:'.$machine)->assertOk()->assertSeeText('No matching activity.');
        $this->get('/entries/'.$entry)->assertSee('compact-board activity')->assertSeeText('grant.revoke')->assertViewHas('events', fn ($events) => $events->first()->actor_label === 'ai-os'
            && $events->first()->subject_label === 'Private name');
    }

    public function test_activity_names_setup_users_requests_and_removed_subjects(): void
    {
        $entry = $this->entry();
        [$machine, $token, $grant] = $this->machine($entry, true);
        $op = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $entry, 'action' => 'test.http', 'request_id' => 'request-subject'])->json('id');
        $type = DB::table('entry_types')->where('key', 'api_key')->first();
        $missing = (string) Str::uuid();
        $subjects = [
            ['member.change', $this->owner->id, 'Owner'], ['workspace.create', $this->workspace, 'Test'],
            ['project.rename', DB::table('projects')->value('id'), 'Test project'], ['environment.update', $this->environment, 'stage'],
            ['type.save', $type->id, 'API key'], ['type.save', $type->key, 'API key'], ['type.delete', $missing, 'removed item'],
            ['entry.create', $entry, 'Private name'], ['machine.create', $machine, 'ai-os'],
            ['grants.revoke', $grant, 'Private name'], ['operation.request', $op, 'Private name'],
        ];
        foreach ($subjects as [$event, $id]) {
            app(\App\Vault\Access::class)->audit($this->workspace, $event, $id);
        }
        $events = $this->get('/workspaces/'.$this->workspace.'/audit')->assertOk()->viewData('events')->keyBy('subject');
        foreach ($subjects as [, $id, $label]) {
            $this->assertSame($label, $events[$id]->subject_label);
        }
        $this->assertNull($events[$missing]->subject_url);
    }

    public function test_owner_decision_labels_and_return_paths(): void
    {
        $entry = $this->entry();
        [$machine, $token] = $this->machine($entry, true);
        $base = '/workspaces/'.$this->workspace;
        $foreign = $this->entryInOtherWorkspace();
        $targets = ['/entries/'.$entry, '/entries/'.$foreign, 'https://example.test', '/entries/'.$entry.'?next=elsewhere'];
        foreach ($targets as $index => $back) {
            $op = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $entry, 'action' => 'test.http', 'request_id' => 'decision-'.$index])->json('id');
            $this->post($base.'/operations/'.$op.'/decision', ['decision' => 'deny', 'back' => $back])
                ->assertRedirect($index === 0 ? $back : $base.'/requests');
        }
        $expired = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $entry, 'action' => 'test.http', 'request_id' => 'decision-expired'])->json('id');
        $deadline = now()->subMinute()->toDateTimeString();
        DB::table('operations')->where('id', $expired)->update(['expires_at' => $deadline]);
        $mfa = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $entry, 'action' => 'test.http', 'request_id' => 'decision-mfa'])->json('id');
        DB::table('operations')->where('id', $mfa)->update(['state' => 'requires_user_action']);
        foreach ($targets as $index => $back) {
            $this->post($base.'/operations/'.$mfa.'/mfa', ['code' => '123456', 'back' => $back])
                ->assertRedirect($index === 0 ? $back : $base.'/requests');
        }
        $history = $this->get($base.'/requests')->assertOk()->viewData('history')->keyBy('id');
        $this->assertSame('denied', $history[$op]->decision_label);
        $this->assertSame('revoked', $history[$op]->state);
        $this->assertSame('expired', $history[$expired]->decision_label);
        $this->assertSame($deadline, $history[$expired]->expires_at);
        $this->assertSame('pending', $history[$expired]->state);
    }

    private function entryInOtherWorkspace(): string
    {
        $workspace = (string) Str::uuid();
        $project = (string) Str::uuid();
        $environment = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $workspace, 'name' => 'Other']);
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $workspace, 'name' => 'Other project']);
        DB::table('environments')->insert(['id' => $environment, 'project_id' => $project, 'name' => 'test']);
        $row = (array) DB::table('entries')->first();
        $row['id'] = (string) Str::uuid();
        $row['environment_id'] = $environment;
        DB::table('entries')->insert($row);

        return $row['id'];
    }

    public function test_grant_approval_and_due_filter(): void
    {
        $entry = $this->entry();
        [$machine, , $grant] = $this->machine($entry, true);
        $url = '/workspaces/'.$this->workspace.'/grants';
        $this->get($url)->assertOk()->assertSeeText('Approval')->assertSeeText('asks every time')->assertDontSeeText('Clear filters')
            ->assertSee('<select name="machine_id" required><option value="">Choose an agent</option>', false)
            ->assertSee('<select name="action" required><option value="">Choose an action</option>', false)
            ->assertSee('<select name="entry_id" required><option value="">Choose an entry</option>', false)
            ->assertSeeText('test.http');
        $this->get($url.'?agent='.$machine.'&entry='.$entry)->assertOk()->assertSeeText('Showing 1 of 1 grants')
            ->assertSeeText('Agent: ai-os')->assertSeeText('Entry: Private name')->assertSeeText('Clear filters');
        DB::table('grants')->where('id', $grant)->update(['approval' => false]);
        $this->get($url)->assertOk()->assertSeeText('runs without asking')->assertSee('/revoke/grants/'.$grant);
        DB::table('machines')->where('id', $machine)->update(['revoked_at' => now()]);
        $this->get($url)->assertOk()->assertDontSee('/revoke/grants/'.$grant)->assertSeeText('agent revoked');
        $this->get('/?q=Private')->assertOk()->assertSeeText('Search: Private')
            ->assertSee('<button class="catalog-search">Search</button>', false)->assertDontSee('<span class="sr-only"> active</span>', false);
        $this->get('/?project=missing')->assertSeeText('Showing 0 of 1 entries')->assertViewHas('archivedCount', 0);
        $this->get('/?due=1')->assertSeeText('Showing 0 of 1 entries')->assertSee('value="due" selected>Rotation due', false)->assertViewHas('scopeCount', 1)->assertViewHas('entries', fn ($entries) => $entries->isEmpty())
            ->assertViewHas('stats', fn ($stats) => $stats['active'] === 1);
        DB::table('entries')->where('id', $entry)->update(['rotate_at' => '2020-11-01 12:00:00']);
        $filters = ['workspace' => $this->workspace, 'project' => DB::table('environments')->where('id', $this->environment)->value('project_id'),
            'environment' => $this->environment, 'q' => 'Private', 'type' => 'api_key'];
        foreach ([false, true] as $due) {
            $current = $filters + ($due ? ['due' => 1] : []) + ['page' => 2];
            $target = $filters + ($due ? [] : ['state' => 'due']);
            $this->get('/?'.http_build_query($current))->assertOk()
                ->assertSee('href="'.e('/?'.http_build_query($target)).'"'.($due ? ' aria-current="true"' : ''), false);
        }
        $this->get('/?state=due')->assertSeeText('State: Rotation due')->assertSeeText('Showing 1 of 1 entries')
            ->assertSeeInOrder(['data-label="Entry"', 'rotation due since 2020-11-01', 'data-label="Project"'], false);
        $this->get('/?due=1&state=archived')->assertViewHas('entries', fn ($entries) => $entries->pluck('id')->all() === [$entry]);
        DB::table('entries')->where('id', $entry)->update(['state' => 'archived']);
        $this->get('/')->assertViewHas('archivedCount', 1)->assertSeeText('1 archived entry →');
        app(Catalog::class)->create(['environment_id' => $this->environment, 'name' => 'Active', 'alias' => 'still-active',
            'type' => 'api_key', 'secret' => '{"token":"test"}']);
        $this->get('/?state=archived')->assertSee('<h1>Entries</h1>', false)->assertSeeText('Showing 1 of 1 entries')
            ->assertSeeText('State: Archived')->assertSee('<span class="chip muted">archived</span>', false)->assertSeeText('used by agents');
        $this->get('/?state=archived&q=missing')->assertSeeText('Showing 0 of 1 entries');
    }

    public function test_grants_order_by_effective_state_then_agent_and_action(): void
    {
        $entry = $this->entry();
        [$agent, , $first] = $this->machine($entry);
        $other = (string) Str::uuid();
        DB::table('machines')->insert(['id' => $other, 'workspace_id' => $this->workspace, 'name' => 'zebra',
            'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay()]);
        $ids = [];
        foreach ([[$other, 'aaa', false], [$agent, 'zzz', false], [$agent, 'aaa', true]] as [$machine, $action, $expired]) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            DB::table('grants')->insert(['id' => $id, 'machine_id' => $machine, 'entry_id' => $entry,
                'action' => $action, 'expires_at' => $expired ? now()->subDay() : now()->addDay()]);
        }
        $this->get('/workspaces/'.$this->workspace.'/grants')->assertViewHas('grants',
            fn ($grants) => $grants->pluck('id')->all() === [$first, $ids[1], $ids[0], $ids[2]]);
    }

    public function test_catalog_remembers_only_a_workspace_the_user_can_access(): void
    {
        $other = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $other, 'name' => 'Zebra']);
        DB::table('members')->insert(['workspace_id' => $other, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->withSession(['last_workspace' => $other])->get('/')->assertViewHas('space', fn ($space) => $space->id === $other);
        DB::table('members')->where('workspace_id', $other)->delete();
        $this->get('/')->assertViewHas('space', fn ($space) => $space->id === $this->workspace);
    }

    public function test_revoked_dates_override_expiry(): void
    {
        $entry = $this->entry();
        [$agent, , $grant] = $this->machine($entry);
        $base = '/workspaces/'.$this->workspace;
        $revoked = '2024-05-06 11:30:00';
        foreach ([[$revoked, null], [null, $revoked], [$revoked, $revoked]] as [$grantDate, $agentDate]) {
            DB::table('grants')->where('id', $grant)->update(['revoked_at' => $grantDate, 'expires_at' => '2025-02-01 00:00:00']);
            DB::table('machines')->where('id', $agent)->update(['revoked_at' => $agentDate, 'expires_at' => '2025-01-01 00:00:00']);
            $response = $this->get($base.'/grants')->assertOk()->assertSeeText('Revoked 2024-05-06 11:30 UTC')
                ->assertDontSeeText('2025-02-01')->assertDontSeeText('2025-01-01');
            if ($agentDate) {
                $response->assertSeeTextInOrder(['Revoked 2024-05-06 11:30 UTC', 'agent revoked']);
                $this->get($base.'/agents')->assertSeeText('Revoked 2024-05-06 11:30 UTC')->assertDontSeeText('2025-01-01');
            } else {
                $response->assertDontSeeText('agent revoked');
            }
        }
    }

    public function test_expired_agent_and_missing_grant_prerequisites(): void
    {
        $base = '/workspaces/'.$this->workspace;
        $this->get($base.'/grants')->assertSeeText('No grants yet.')->assertSeeText('No active agents.')->assertSeeText('No active entries.')
            ->assertDontSee('name="machine_id"', false);
        $entry = $this->entry();
        $this->get($base.'/grants?entry='.$entry)->assertSeeText('No matching grants.');
        [$agent] = $this->machine($entry);
        $this->get($base.'/agents')->assertSee('/revoke/machines/'.$agent);
        DB::table('machines')->where('id', $agent)->update(['expires_at' => '2020-11-25 14:00:00']);
        $this->get($base.'/agents')->assertDontSee('/revoke/machines/'.$agent)->assertSee('Expired');
        $this->get($base.'/grants')->assertSeeText('No active agents.')->assertDontSeeText('No active entries.')
            ->assertSeeText('Expired 2020-11-25 14:00 UTC')->assertSeeText('agent expired')->assertSeeText('Grant expiry:');
        DB::table('machines')->where('id', $agent)->update(['expires_at' => now()->addDay()]);
        file_put_contents($this->temp.'/connectors.json', '{"actions":{}}');
        $this->get($base.'/grants')->assertSeeText('No actions are configured.')->assertDontSee('name="machine_id"', false);
    }

    public function test_activity_uses_event_time_before_id_on_both_pages(): void
    {
        $entry = $this->entry();
        $audit = app(\App\Vault\Access::class);
        $audit->audit($this->workspace, 'entry.older', $entry);
        $audit->audit($this->workspace, 'entry.newer', $entry);
        DB::table('audit')->where('event', 'entry.older')->update(['created_at' => now()->addDays(2)]);
        DB::table('audit')->where('event', 'entry.newer')->update(['created_at' => now()->addDay()]);
        foreach (['/workspaces/'.$this->workspace.'/audit', '/entries/'.$entry] as $url) {
            $this->get($url)->assertOk()->assertViewHas('events', fn ($events) => $events->first()->event === 'entry.older');
        }
    }

    public function test_member_row_role_change_and_removal(): void
    {
        $member = User::create(['id' => (string) Str::uuid(), 'name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
        $identity = ['email' => $member->email, 'name' => $member->name];
        $url = '/workspaces/'.$this->workspace.'/members';
        $this->get($url)->assertOk()->assertSeeText('Add another owner before changing your role')
            ->assertSee('<label for="first-password"><span>First password <small>new user only</small></span></label>', false)
            ->assertSee('aria-label="Generate first password"', false)
            ->assertDontSee('aria-label="Role for Owner"', false);
        $this->post($url, $identity + ['role' => 'owner'])->assertRedirect($url);
        $this->get($url)->assertSee('aria-label="Role for Owner"', false)->assertDontSeeText('Add another owner');
        $this->post($url, $identity + ['role' => 'viewer'])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('aria-label="Role for Member"', false)->assertSee('Remove Member from this workspace?');
        $this->post($url, $identity + ['role' => 'editor'])->assertRedirect($url);
        $this->assertDatabaseHas('members', ['workspace_id' => $this->workspace, 'user_id' => $member->id, 'role' => 'editor']);
        $this->post($url, $identity + ['role' => 'remove'])->assertRedirect($url);
        $this->assertDatabaseMissing('members', ['workspace_id' => $this->workspace, 'user_id' => $member->id]);
        $this->post($url, ['name' => 'Owner', 'email' => $this->owner->email, 'role' => 'viewer'])->assertConflict();
    }

    public function test_member_and_import_errors_return_to_their_forms(): void
    {
        $base = '/workspaces/'.$this->workspace;
        $cases = [
            ['members', ['name' => 'New member', 'email' => 'new@example.test', 'role' => 'editor'], 'password', 'Enter a first password for a new user.'],
            ['import', ['import' => '{'], 'import', 'Paste a JSON array of 1 to 50 entry objects.'],
            ['import', ['import' => '[[]]'], 'import', 'Paste a JSON array of 1 to 50 entry objects.'],
        ];
        foreach ($cases as [$page, $input, $field, $message]) {
            $url = $base.'/'.$page;
            $this->get($url)->assertOk();
            $this->post($url, $input)->assertStatus(303)->assertRedirect($url)
                ->assertSessionHas('form_errors', fn ($errors) => $errors[$field] === [$message]);
            $this->get($url)->assertSeeText($message);
        }
    }

    public function test_activity_filters_by_outcome_and_inclusive_dates(): void
    {
        foreach ([['01', 'denied'], ['02', 'ok'], ['02', 'denied'], ['03', 'denied']] as [$day, $outcome]) {
            DB::table('audit')->insert(['workspace_id' => $this->workspace, 'actor' => 'user:'.$this->owner->id,
                'event' => 'entry.reveal', 'subject' => null, 'outcome' => $outcome, 'created_at' => '2026-01-'.$day.' 23:59:59']);
        }
        $this->get('/workspaces/'.$this->workspace.'/audit')->assertOk()->assertDontSee('class="filter-status"', false)->assertSeeText('Page 1 of 1');
        $this->get('/workspaces/'.$this->workspace.'/audit?outcome=ok')->assertSeeText('Outcome: OK');
        $this->get('/workspaces/'.$this->workspace.'/audit?outcome=denied&from=2026-01-02&to=2026-01-02')
            ->assertOk()->assertSeeText('Showing 1 of 4 events')->assertSeeText('Outcome: Denied')
            ->assertSeeText('From: 2026-01-02')->assertSeeText('To: 2026-01-02')
            ->assertViewHas('events', fn ($events) => $events->first()->created_at === '2026-01-02 23:59:59' && $events->first()->outcome === 'denied');
    }

    public function test_import_validates_all_rows_before_any_write(): void
    {
        $row = ['environment_id' => $this->environment, 'name' => 'Import', 'alias' => 'import', 'type' => 'api_key', 'secret' => '{"token":"synthetic-import"}'];
        $this->post('/workspaces/'.$this->workspace.'/import', ['import' => json_encode([$row, [...$row, 'environment_id' => (string) Str::uuid()]])])->assertNotFound();
        $this->assertSame(0, DB::table('entries')->count());
        $this->assertSame([], $this->backend);
        $this->post('/workspaces/'.$this->workspace.'/import', ['import' => json_encode([$row])])->assertRedirect();
        $this->assertSame(1, DB::table('entries')->count());
    }

    public function test_backend_errors_do_not_leak_secret_or_exception(): void
    {
        $id = $this->entry();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('internal credential='.$this->sentinel, 500)]);
        $this->post('/entries/'.$id.'/reveal')->assertStatus(503)->assertDontSee($this->sentinel)->assertDontSee('internal credential');
    }

    public function test_mcp_exposes_actions_not_secret_reads_and_rejects_browser_origin(): void
    {
        $id = $this->entry();
        [$machine,$token] = $this->machine($id);
        $response = $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
        $this->assertSame(['vault_search', 'vault_execute', 'vault_status', 'vault_cancel'], array_column($response->json('result.tools'), 'name'));
        $this->withToken($token)->withHeader('Origin', 'https://evil.example')->getJson('/api/v1/catalog')->assertForbidden();
    }

    public function test_pending_operation_is_revoked_when_grant_is_removed(): void
    {
        $id = $this->entry();
        [$machine,$token,$grant] = $this->machine($id, true);
        $op = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $id, 'action' => 'test.http', 'request_id' => 'pending-1234'])->json('id');
        DB::table('grants')->where('id', $grant)->update(['revoked_at' => now()]);
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'revoked');
    }

    public function test_archive_restore_and_version_restore_do_not_restore_machine_grants(): void
    {
        $id = $this->entry();
        [$machine,$token,$grant] = $this->machine($id);
        app(Catalog::class)->update($id, ['environment_id' => $this->environment, 'name' => 'Name', 'alias' => 'alias', 'type' => 'api_key', 'secret' => '{"token":"synthetic-rotation"}']);
        $this->post('/entries/'.$id.'/restore-version', ['version' => 1])->assertRedirect();
        $this->assertSame(3, DB::table('entries')->where('id', $id)->value('version'));
        $this->assertSame($this->sentinel, app(Catalog::class)->reveal($id)['token']);
        $this->post('/entries/'.$id.'/archive')->assertRedirect();
        $this->post('/entries/'.$id.'/reveal')->assertConflict();
        $this->get('/?state=archived')->assertOk()->assertSee('/entries/'.$id, false);
        $this->post('/entries/'.$id.'/unarchive')->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/catalog')->assertExactJson(['entries' => [], 'next_page' => null]);
        $this->post('/entries/'.$id.'/destroy', ['confirmation' => 'DELETE'])->assertConflict();
    }

    public function test_metadata_type_change_and_machine_owner_reveal_are_denied(): void
    {
        $id = $this->entry();
        [$machine,$token] = $this->machine($id);
        $this->post('/entries/'.$id, ['environment_id' => $this->environment, 'name' => 'Name', 'alias' => 'alias', 'type' => 'login', 'secret' => '{"username":"u","password":"p"}'])->assertStatus(303)->assertSessionHasErrors('entry');
        $this->app['auth']->logout();
        $this->withToken($token)->postJson('/entries/'.$id.'/reveal')->assertRedirect('/login');
    }

    public function test_recovery_invalidates_pending_mfa_and_authenticated_revisions(): void
    {
        $totp = TOTP::generate();
        $this->backend['/v1/secret/data/auth/'.$this->owner->id][1] = ['totp' => $totp->getSecret()];
        $this->app['auth']->logout();
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'Synthetic-password-123!'])->assertRedirect('/mfa');
        app(AccountRecovery::class)->reset($this->owner->email, 'New-synthetic-password-long-enough');
        $this->get('/mfa')->assertUnauthorized();
        $this->post('/mfa', ['code' => $totp->now()])->assertUnauthorized();
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'Synthetic-password-123!'])->assertUnauthorized();
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'New-synthetic-password-long-enough'])->assertRedirect('/mfa');
        $this->get('/mfa')->assertOk();
        $oldRevision = $this->owner->credentialRevision();
        $this->owner->refresh();
        $this->actingAs($this->owner)->withSession(['mfa_at' => time(), 'login_at' => time(), 'auth_revision' => $oldRevision])->get('/')->assertRedirect('/login');
    }

    public function test_regrant_never_revives_an_old_approval(): void
    {
        $id = $this->entry();
        [$machine,$token,$grant] = $this->machine($id, true);
        $op = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $id, 'action' => 'test.http', 'request_id' => 'request-before-revoke'])->json('id');
        $this->post('/workspaces/'.$this->workspace.'/operations/'.$op.'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->post('/workspaces/'.$this->workspace.'/revoke/grants/'.$grant)->assertRedirect('/workspaces/'.$this->workspace.'/grants');
        $this->post('/workspaces/'.$this->workspace.'/grants', ['machine_id' => $machine, 'entry_id' => $id, 'action' => 'test.http', 'days' => 1, 'approval' => '1'])->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertJsonPath('state', 'revoked');
        $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $id, 'action' => 'test.http', 'request_id' => 'request-after-revoke'])->assertJsonPath('state', 'pending');
    }

    public function test_late_broker_poll_cannot_overwrite_cancellation(): void
    {
        $id = $this->entry();
        [$machine,$token] = $this->machine($id);
        $op = $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $id, 'action' => 'test.http', 'request_id' => 'concurrent-cancel'])->json('id');
        Http::swap(new Factory);
        Http::fake(function () use ($op) {
            DB::table('operations')->where('id', $op)->update(['state' => 'revoked']);

            return Http::response(['state' => 'running']);
        });
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertJsonPath('state', 'revoked');
        $this->assertNull(app(Operations::class)->authorized($op));
    }

    public function test_web_partial_edit_preserves_ssh_passphrase_and_imported_fields(): void
    {
        $id = app(Catalog::class)->create(['environment_id' => $this->environment, 'name' => 'Key', 'alias' => 'ssh-key', 'type' => 'ssh', 'secret' => json_encode(['private_key' => 'synthetic-key', 'passphrase' => 'synthetic-passphrase', 'custom' => 'synthetic-extra'])]);
        $data = ['environment_id' => $this->environment, 'name' => 'Renamed key', 'alias' => 'ssh-key', 'type' => 'ssh', 'secret_fields' => ['private_key' => 'synthetic-new-key', 'passphrase' => '']];
        $this->post('/entries/'.$id, $data)->assertRedirect();
        $this->assertSame(['private_key' => 'synthetic-new-key', 'passphrase' => 'synthetic-passphrase', 'custom' => 'synthetic-extra'], app(Catalog::class)->reveal($id));
        $this->post('/entries/'.$id, [...$data, 'clear_fields' => ['passphrase']])->assertRedirect();
        $this->assertArrayNotHasKey('passphrase', app(Catalog::class)->reveal($id));
        $this->get('/entries/new?workspace='.$this->workspace.'&entry='.$id)->assertOk()->assertDontSee('synthetic-new-key')->assertDontSee('synthetic-extra');
    }

    public function test_machine_search_filters_authorized_scope_and_paginates(): void
    {
        $id = $this->entry();
        [$machine,$token] = $this->machine($id);
        $other = $this->entry();
        $grant = (string) Str::uuid();
        DB::table('grants')->insert(['id' => $grant, 'machine_id' => $machine, 'entry_id' => $other, 'action' => 'test.http', 'approval' => false, 'expires_at' => now()->addHour()]);
        $this->withToken($token)->getJson('/api/v1/catalog?query=safe&per_page=1')->assertJsonCount(1, 'entries')->assertJsonPath('next_page', 2)->assertJsonPath('entries.0.environment', 'stage');
        $this->withToken($token)->getJson('/api/v1/catalog?query=safe&per_page=1&page=2')->assertJsonCount(1, 'entries')->assertJsonPath('next_page', null);
        $this->withToken($token)->getJson('/api/v1/catalog?query=missing')->assertExactJson(['entries' => [], 'next_page' => null]);
        $this->withToken($token)->getJson('/api/v1/catalog?environment_id='.(string) Str::uuid())->assertExactJson(['entries' => [], 'next_page' => null]);
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'vault_search', 'arguments' => ['per_page' => 101]]])->assertJsonPath('error.code', -32602);
    }

    public function test_custom_type_defines_fields_and_cannot_be_deleted_while_used(): void
    {
        $types = '/workspaces/'.$this->workspace.'/types';
        $this->post($types, ['name' => 'FTP server', 'field_key' => ['', '', ''], 'field_label' => ['Server address', 'Contraseña', ''],
            'field_kind' => ['text', 'secret', 'text'], 'field_required' => ['1', '1', '0']])->assertRedirect();
        $type = DB::table('entry_types')->where('workspace_id', $this->workspace)->where('name', 'FTP server')->first();
        $this->assertSame('ftp_server', $type->key);
        $this->assertSame(['server_address', 'contrasena'], array_column(json_decode($type->fields, true), 'key'));
        $this->get('/entries/new?workspace='.$this->workspace)->assertOk()->assertSee('Server address')->assertSee('secret_fields[contrasena]', false);

        $entry = ['environment_id' => $this->environment, 'name' => 'FTP', 'alias' => 'ftp', 'type' => 'ftp_server'];
        $this->post('/entries', [...$entry, 'secret_fields' => ['server_address' => 'ftp.example.test']])->assertStatus(303)->assertSessionHasErrors()->assertSessionHas('errors', fn ($errors) => str_contains(implode(' ', $errors->all()), 'Contraseña'));
        $this->post('/entries', [...$entry, 'secret_fields' => ['server_address' => 'ftp.example.test', 'contrasena' => $this->sentinel]])->assertRedirect();
        $this->assertSame(1, DB::table('entries')->where('type', 'ftp_server')->count());
        $this->post('/entries', [...$entry, 'type' => 'missing_type', 'secret_fields' => ['x' => 'y']])->assertStatus(303)->assertSessionHasErrors();

        $this->post($types.'/ftp_server/delete')->assertStatus(303)->assertSessionHasErrors();
        $this->post($types, ['name' => 'Unused', 'field_label' => ['Field'], 'field_key' => [''], 'field_kind' => ['text'], 'field_required' => ['0']]);
        $this->post($types.'/unused/delete')->assertRedirect();
        $this->assertFalse(DB::table('entry_types')->where('key', 'unused')->exists());
        $this->assertTrue(DB::table('entry_types')->where('key', 'secure_note')->exists(), 'built-in types are seeded per workspace');
    }

    public function test_built_in_types_are_editable_and_removed_fields_stay_readable(): void
    {
        $id = $this->entry();
        $types = '/workspaces/'.$this->workspace.'/types/api_key';
        $this->post($types, ['name' => 'Access key', 'field_key' => ['token', ''], 'field_label' => ['Access token', 'Endpoint'],
            'field_kind' => ['secret', 'text'], 'field_required' => ['1', '0']])->assertRedirect();
        $this->get('/entries/'.$id)->assertOk()->assertSee('Access key');
        $this->post('/entries/'.$id, ['environment_id' => $this->environment, 'name' => 'Private name', 'alias' => 'safe-alias', 'type' => 'api_key',
            'secret_fields' => ['endpoint' => 'https://api.example.test']])->assertRedirect();
        $this->assertSame(['token' => $this->sentinel, 'endpoint' => 'https://api.example.test'], app(Catalog::class)->reveal($id));
        $this->post($types, ['name' => 'Access key', 'field_key' => ['token', 'endpoint'], 'field_label' => ['Access token', 'Endpoint'],
            'field_kind' => ['secret', 'text'], 'field_required' => ['1', '0'], 'field_remove' => ['endpoint']])->assertRedirect();
        $this->post('/entries/'.$id.'/reveal')->assertOk()->assertSee('https://api.example.test')->assertSee('no longer in type');
    }

    public function test_grant_requires_fields_the_action_reads(): void
    {
        $id = app(Catalog::class)->create(['environment_id' => $this->environment, 'name' => 'Note', 'alias' => 'note', 'type' => 'secure_note', 'secret' => '{"note":"synthetic"}']);
        $machine = (string) Str::uuid();
        DB::table('machines')->insert(['id' => $machine, 'workspace_id' => $this->workspace, 'name' => 'ai-os', 'token_hash' => str_repeat('c', 64), 'expires_at' => now()->addDay()]);
        $this->post('/workspaces/'.$this->workspace.'/grants', ['machine_id' => $machine, 'entry_id' => $id, 'action' => 'test.http', 'days' => 1])->assertStatus(303)->assertSessionHasErrors();
        $this->post('/workspaces/'.$this->workspace.'/grants', ['machine_id' => $machine, 'entry_id' => $this->entry(), 'action' => 'test.http', 'days' => 1])->assertRedirect();
    }

    public function test_sparse_checkbox_indices_follow_their_rows(): void
    {
        $base = '/workspaces/'.$this->workspace;
        $names = [0 => 'First', 1 => 'Second', 2 => 'Third', 8 => 'Added'];
        $checked = [0 => '1', 2 => '1', 8 => '1'];
        $this->post($base.'/template', ['env_name' => $names, 'env_production' => $checked])->assertRedirect()->assertSessionHasNoErrors();
        $template = DB::table('workspaces')->where('id', $this->workspace)->value('environment_template');
        $this->assertSame([true, false, true, true], array_column(json_decode($template, true), 'production'));

        $this->post($base.'/types', ['name' => 'Sparse fields', 'field_label' => $names, 'field_required' => $checked])->assertRedirect()->assertSessionHasNoErrors();
        $fields = DB::table('entry_types')->where('workspace_id', $this->workspace)->where('name', 'Sparse fields')->value('fields');
        $this->assertSame([true, false, true, true], array_column(json_decode($fields, true), 'required'));
    }

    public function test_environment_template_rename_production_and_safe_delete(): void
    {
        $base = '/workspaces/'.$this->workspace;
        $this->post($base.'/template', ['env_name' => ['dev', 'qa', 'live', ''], 'env_production' => ['0', '0', '1', '0']])->assertRedirect();
        $this->post($base.'/projects', ['name' => 'Shop'])->assertRedirect();
        $project = DB::table('projects')->where('name', 'Shop')->value('id');
        $envs = DB::table('environments')->where('project_id', $project)->orderBy('name')->get();
        $this->assertSame(['dev', 'live', 'qa'], $envs->pluck('name')->all());
        $this->assertTrue((bool) $envs->firstWhere('name', 'live')->production);
        $this->post($base.'/projects', ['name' => 'Shop'])->assertStatus(303)->assertSessionHasErrors();

        $dev = $envs->firstWhere('name', 'dev')->id;
        $this->post($base.'/environments/'.$dev, ['name' => 'development', 'production' => '0'])->assertRedirect();
        $this->post($base.'/environments/'.$dev, ['name' => 'qa', 'production' => '0'])->assertStatus(303)->assertSessionHasErrors();
        $this->post($base.'/environments', ['project_id' => $project, 'name' => 'preprod', 'production' => '1'])->assertRedirect();
        $this->assertTrue((bool) DB::table('environments')->where('project_id', $project)->where('name', 'preprod')->value('production'));
        $this->get($base.'/structure')->assertOk()->assertSee('development')->assertSee('preprod');

        $this->post($base.'/environments/'.$this->environment.'/delete')->assertRedirect();
        $this->assertFalse(DB::table('environments')->where('id', $this->environment)->exists());
        $this->post($base.'/environments/'.$dev.'/delete')->assertRedirect();
        app(Catalog::class)->create(['environment_id' => $envs->firstWhere('name', 'qa')->id, 'name' => 'n', 'alias' => 'a', 'type' => 'api_key', 'secret' => '{"token":"t"}']);
        $this->post($base.'/environments/'.$envs->firstWhere('name', 'qa')->id.'/delete')->assertStatus(303)->assertSessionHasErrors();
        $this->post($base.'/projects/'.$project.'/delete')->assertStatus(303)->assertSessionHasErrors();
        $this->post($base.'/projects/'.$project, ['name' => 'Shop 2'])->assertRedirect();
        $this->assertSame('Shop 2', DB::table('projects')->where('id', $project)->value('name'));

        $other = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $other, 'name' => 'Foreign']);
        $this->post('/workspaces/'.$other.'/environments/'.$dev.'/delete')->assertNotFound();
        DB::table('members')->where('user_id', $this->owner->id)->update(['role' => 'viewer']);
        $this->get($base.'/structure')->assertNotFound();
        $this->get($base.'/types')->assertNotFound();
    }

    public function test_every_change_is_logged_without_values_and_old_versions_can_be_compared(): void
    {
        $id = $this->entry();
        $this->post('/entries/'.$id, ['environment_id' => $this->environment, 'name' => 'Renamed', 'alias' => 'safe-alias', 'type' => 'api_key', 'tags' => 'billing',
            'secret_fields' => ['token' => 'synthetic-rotated']])->assertRedirect();
        $changes = DB::table('entry_changes')->where('entry_id', $id)->orderBy('version')->get();
        $this->assertSame(['create', 'update'], $changes->pluck('event')->all());
        $update = json_decode($changes[1]->changes, true);
        $this->assertSame(['token'], $update['fields']['changed']);
        $this->assertSame(['Private name', 'Renamed'], $update['meta']['name']);
        $this->assertSame((string) $this->owner->id, $changes[1]->user_id);
        $this->assertStringNotContainsString($this->sentinel, json_encode(DB::table('entry_changes')->get()));
        $this->assertStringNotContainsString('synthetic-rotated', json_encode(DB::table('entry_changes')->get()));

        $this->get('/entries/'.$id)->assertOk()->assertSee('Versions')->assertSee('API key')->assertSee('“Private name” → “Renamed”', false)->assertDontSee($this->sentinel);
        $this->post('/entries/'.$id.'/reveal', ['version' => 1])->assertOk()->assertSee($this->sentinel)->assertSee('older version 1', false)->assertSee('differs')->assertDontSee('synthetic-rotated');

        $this->post('/entries/'.$id.'/restore-version', ['version' => 1])->assertRedirect();
        $restore = DB::table('entry_changes')->where('entry_id', $id)->where('version', 3)->first();
        $this->assertSame('restore', $restore->event);
        $this->assertSame(1, json_decode($restore->changes, true)['restored_from']);
        $this->get('/entries/'.$id)->assertSee('from version 1');
    }

    private function typeForm(array $overrides = []): array
    {
        return [...['name' => 'API key', 'field_key' => ['token'], 'field_label' => ['API key'], 'field_kind' => ['secret'], 'field_required' => ['1']], ...$overrides];
    }

    public function test_type_key_is_canonical_and_usage_ignores_case_and_destroyed_entries(): void
    {
        $row = ['environment_id' => $this->environment, 'name' => 'Import', 'alias' => 'import', 'type' => 'API_KEY', 'secret' => '{"token":"synthetic-import"}'];
        $this->post('/workspaces/'.$this->workspace.'/import', ['import' => json_encode([$row])])->assertStatus(303)->assertSessionHasErrors();
        $this->post('/entries', $row)->assertStatus(303)->assertSessionHasErrors();
        $this->assertSame(0, DB::table('entries')->count());

        // A legacy row whose type differs only by case still counts as usage.
        $id = $this->entry();
        DB::table('entries')->where('id', $id)->update(['type' => 'API_KEY']);
        $types = '/workspaces/'.$this->workspace.'/types';
        $this->post($types.'/api_key/delete')->assertStatus(303)->assertSessionHasErrors();
        $this->assertSame(1, $this->get($types)->assertOk()->viewData('usage')['api_key']);
        $this->assertTrue(DB::table('entry_types')->where('key', 'api_key')->exists());

        // Destroyed entries keep their row but no longer pin the type.
        DB::table('entries')->where('id', $id)->update(['state' => 'destroyed']);
        $this->assertArrayNotHasKey('api_key', $this->get($types)->viewData('usage'));
        $this->assertSame(0, (int) ($this->get('/workspaces/'.$this->workspace.'/structure')->assertOk()->viewData('counts')[$this->environment] ?? 0));
        $this->post($types.'/api_key/delete')->assertRedirect();
        $this->assertFalse(DB::table('entry_types')->where('key', 'api_key')->exists());
    }

    public function test_history_names_only_declared_fields_and_counts_the_rest(): void
    {
        $catalog = app(Catalog::class);
        $base = ['environment_id' => $this->environment, 'name' => 'Private name', 'alias' => 'safe-alias', 'type' => 'api_key'];
        $id = $catalog->create([...$base, 'secret' => json_encode(['token' => 't1', 'customer_acme_prod_pw' => 'x', 'internal_host' => 'y'])]);
        $catalog->update($id, [...$base, 'secret' => json_encode(['token' => 't2', 'customer_acme_prod_pw' => 'changed', 'another_undeclared' => 'z'])]);
        $stored = json_encode(DB::table('entry_changes')->where('entry_id', $id)->get());
        foreach (['customer_acme_prod_pw', 'internal_host', 'another_undeclared'] as $key) {
            $this->assertStringNotContainsString($key, $stored);
        }
        $changes = DB::table('entry_changes')->where('entry_id', $id)->orderBy('version')->pluck('changes')->map(fn ($c) => json_decode($c, true)['fields'])->all();
        $this->assertSame(['token'], $changes[0]['added']);
        $this->assertSame(2, $changes[0]['other_added']);
        $this->assertSame(['token'], $changes[1]['changed']);
        $this->assertSame([1, 1, 1], [$changes[1]['other_added'], $changes[1]['other_changed'], $changes[1]['other_removed']]);

        // Rows written before this rule are filtered when read, so a viewer never sees undeclared names.
        DB::table('entry_changes')->where('entry_id', $id)->where('version', 1)->update(['changes' => json_encode(['fields' => ['added' => ['token', 'legacy_leaky_name'], 'changed' => [], 'removed' => []], 'meta' => []])]);
        DB::table('members')->where('user_id', $this->owner->id)->update(['role' => 'viewer']);
        $response = $this->get('/entries/'.$id)->assertOk()->assertDontSee('legacy_leaky_name')->assertDontSee('customer_acme_prod_pw');
        $first = collect($response->viewData('history'))->firstWhere('version', 1)['changes']['fields'];
        $this->assertSame(['token'], $first['added']);
        $this->assertSame(1, $first['other_added']);
    }

    public function test_reveal_reports_the_version_it_actually_read(): void
    {
        $id = $this->entry();
        $this->assertSame(['version' => 1, 'payload' => ['token' => $this->sentinel]], app(Catalog::class)->revealVersion($id));
        $this->beforeRead = function () use ($id) {
            $this->backend['/v1/secret/data/entries/'.$id][2] = ['token' => 'synthetic-concurrent'];
            DB::table('entries')->where('id', $id)->update(['version' => 2]);
        };
        $response = $this->post('/entries/'.$id.'/reveal')->assertOk()->assertSee($this->sentinel)->assertDontSee('synthetic-concurrent');
        $this->assertSame(1, $response->viewData('shownVersion'));
        $this->assertSame(['token'], $response->viewData('differs'));
    }

    public function test_grant_requires_current_payload_to_hold_the_action_fields(): void
    {
        $id = app(Catalog::class)->create(['environment_id' => $this->environment, 'name' => 'Note', 'alias' => 'note', 'type' => 'secure_note', 'secret' => '{"note":"synthetic"}']);
        // The type later gains the required field, but this entry's current version does not hold it.
        $this->post('/workspaces/'.$this->workspace.'/types/secure_note', ['name' => 'Note', 'field_key' => ['note', ''], 'field_label' => ['Note', 'Token'],
            'field_kind' => ['multiline', 'secret'], 'field_required' => ['1', '1']])->assertRedirect();
        $machine = (string) Str::uuid();
        DB::table('machines')->insert(['id' => $machine, 'workspace_id' => $this->workspace, 'name' => 'ai-os', 'token_hash' => str_repeat('c', 64), 'expires_at' => now()->addDay()]);
        $grant = ['machine_id' => $machine, 'entry_id' => $id, 'action' => 'test.http', 'days' => 1];
        $this->post('/workspaces/'.$this->workspace.'/grants', $grant)->assertStatus(303)->assertSessionHasErrors()->assertSessionHas('errors', fn ($errors) => !str_contains(implode(' ', $errors->all()), 'synthetic'));
        $this->assertSame(0, DB::table('grants')->count());
        $this->post('/entries/'.$id, ['environment_id' => $this->environment, 'name' => 'Note', 'alias' => 'note', 'type' => 'secure_note', 'secret_fields' => ['token' => 'synthetic-token']])->assertRedirect();
        $this->post('/workspaces/'.$this->workspace.'/grants', $grant)->assertRedirect();
        $this->assertSame(1, DB::table('grants')->count());
    }

    public function test_restore_carries_over_required_fields_added_to_the_type_later(): void
    {
        $id = $this->entry();
        $this->post('/workspaces/'.$this->workspace.'/types/api_key', $this->typeForm(['field_key' => ['token', ''], 'field_label' => ['API key', 'Endpoint'],
            'field_kind' => ['secret', 'text'], 'field_required' => ['1', '1']]))->assertRedirect();
        $this->post('/entries/'.$id, ['environment_id' => $this->environment, 'name' => 'Private name', 'alias' => 'safe-alias', 'type' => 'api_key',
            'secret_fields' => ['token' => 'synthetic-rotated', 'endpoint' => 'https://api.example.test']])->assertRedirect();
        $this->post('/entries/'.$id.'/restore-version', ['version' => 1])->assertRedirect();
        $this->assertSame(['token' => $this->sentinel, 'endpoint' => 'https://api.example.test'], app(Catalog::class)->reveal($id));
        $restore = json_decode(DB::table('entry_changes')->where('entry_id', $id)->where('version', 3)->value('changes'), true);
        $this->assertSame(1, $restore['restored_from']);
        $this->assertSame(['endpoint'], $restore['carried']);
        $this->assertSame(['token'], $restore['fields']['changed']);
        $this->assertSame([], $restore['fields']['removed']);
    }

    public function test_entry_create_checks_the_type_inside_the_locked_transaction(): void
    {
        $this->entry();
        $levels = [];
        DB::listen(function ($query) use (&$levels) {
            foreach (['workspaces', 'entry_types', 'entries'] as $table) {
                if (preg_match('/^(select|insert).*(from|into) "'.$table.'"/', $query->sql)) {
                    $levels[] = [$table, DB::transactionLevel() > 0];
                }
            }
        });
        $this->entry();
        $insert = array_search(['entries', true], $levels, true);
        $this->assertNotFalse($insert, 'entry is inserted inside a transaction');
        $before = array_slice($levels, 0, $insert);
        $typeCheck = array_search(['entry_types', true], $before, true);
        $lock = array_search(['workspaces', true], $before, true);
        $this->assertNotFalse($typeCheck, 'type existence is checked inside the transaction');
        $this->assertNotFalse($lock, 'the workspace row shared with type deletion is locked inside the transaction');
        $this->assertLessThan($typeCheck, $lock);
    }

    public function test_type_edit_rejects_stale_revision_foreign_keys_and_duplicates(): void
    {
        $url = '/workspaces/'.$this->workspace.'/types/api_key';
        $revision = $this->get('/workspaces/'.$this->workspace.'/types')->viewData('types')->firstWhere('key', 'api_key')->revision;
        $this->assertNotEmpty($revision);
        $this->post($url, $this->typeForm(['revision' => $revision, 'field_key' => ['token', ''], 'field_label' => ['API key', 'Endpoint'], 'field_kind' => ['secret', 'text'], 'field_required' => ['1', '0']]))->assertRedirect();
        // A second editor still holding the first form must not silently drop the new field.
        $this->post($url, $this->typeForm(['revision' => $revision]))->assertConflict();
        $fields = fn () => array_column(json_decode(DB::table('entry_types')->where('workspace_id', $this->workspace)->where('key', 'api_key')->value('fields'), true), 'key');
        $this->assertSame(['token', 'endpoint'], $fields());

        // Keys are immutable and server-assigned.
        $this->post($url, $this->typeForm(['field_key' => ['token', 'password'], 'field_label' => ['API key', 'Sneaky'], 'field_kind' => ['secret', 'text'], 'field_required' => ['1', '0']]))->assertStatus(303)->assertSessionHasErrors();
        $this->post($url, $this->typeForm(['field_key' => ['token', 'token'], 'field_label' => ['API key', 'Again'], 'field_kind' => ['secret', 'text'], 'field_required' => ['1', '0']]))->assertStatus(303)->assertSessionHasErrors();
        $this->post('/workspaces/'.$this->workspace.'/types', ['name' => 'Fresh', 'field_key' => ['chosen_by_client'], 'field_label' => ['Label'], 'field_kind' => ['text'], 'field_required' => ['0']])->assertStatus(303)->assertSessionHasErrors();
        $this->assertSame(['token', 'endpoint'], $fields());

        // A new row whose label slugs to an existing key gets a fresh key; the existing key keeps its place and meaning.
        $this->post($url, $this->typeForm(['field_key' => ['', 'token', 'endpoint'], 'field_label' => ['Token', 'API key', 'Endpoint'], 'field_kind' => ['text', 'secret', 'text'], 'field_required' => ['0', '1', '0']]))->assertRedirect();
        $this->assertSame(['token_2', 'token', 'endpoint'], $fields());
    }

    public function test_type_changes_are_audited_with_the_type_row_as_subject(): void
    {
        $types = '/workspaces/'.$this->workspace.'/types';
        $this->post($types, ['name' => 'Audited', 'field_key' => [''], 'field_label' => ['Field'], 'field_kind' => ['text'], 'field_required' => ['0']])->assertRedirect();
        $row = DB::table('entry_types')->where('workspace_id', $this->workspace)->where('key', 'audited')->first();
        $this->assertSame(1, DB::table('audit')->where('event', 'type.save')->where('subject', $row->id)->count());
        $this->post($types.'/audited/delete')->assertRedirect();
        $this->assertSame(1, DB::table('audit')->where('event', 'type.delete')->where('subject', $row->id)->count());
        // A rejected save leaves no "ok" audit row behind.
        $this->post($types, ['name' => 'Rejected', 'field_label' => []])->assertStatus(303)->assertSessionHasErrors();
        $this->assertSame(1, DB::table('audit')->where('event', 'type.save')->count());
    }

    public function test_history_returns_only_the_newest_versions(): void
    {
        $id = $this->entry();
        $catalog = app(Catalog::class);
        foreach ([2, 3, 4] as $n) {
            $catalog->update($id, ['environment_id' => $this->environment, 'name' => 'Private name', 'alias' => 'safe-alias', 'type' => 'api_key', 'secret' => json_encode(['token' => 'synthetic-'.$n])]);
        }
        $entry = app(\App\Vault\Access::class)->entry($id);
        $history = $catalog->history($entry, 3, $older);
        $this->assertSame([4, 3, 2], array_column($history, 'version'));
        $this->assertTrue($older);
        $this->assertSame('update', $history[2]['event']);
        $this->assertCount(4, $catalog->history($entry, 50, $older));
        $this->assertFalse($older);
        $this->assertFalse($this->get('/entries/'.$id)->assertOk()->viewData('historyOlder'));
    }

    public function test_array_shaped_inputs_are_validation_errors_not_server_errors(): void
    {
        $id = $this->entry();
        $this->post('/entries/'.$id, ['environment_id' => $this->environment, 'name' => 'Private name', 'alias' => 'safe-alias', 'type' => 'api_key',
            'secret_fields' => ['token' => 'x'], 'clear_fields' => [['nested']]])->assertStatus(303)->assertSessionHasErrors('clear_fields');
        $types = '/workspaces/'.$this->workspace.'/types';
        $this->post($types, $this->typeForm(['name' => ['x']]))->assertStatus(303)->assertSessionHasErrors();
        foreach (['field_label', 'field_key', 'field_kind', 'field_required', 'field_remove'] as $column) {
            $this->post($types, $this->typeForm([$column => [['nested']]]))->assertStatus(303)->assertSessionHasErrors();
            $this->post($types.'/api_key', $this->typeForm([$column => [['nested']]]))->assertStatus(303)->assertSessionHasErrors();
        }
        $this->post($types.'/api_key', $this->typeForm(['revision' => ['x']]))->assertStatus(303)->assertSessionHasErrors();
    }

    public function test_mfa_replay_is_rejected(): void
    {
        $this->app['auth']->logout();
        $totp = TOTP::generate();
        $this->backend['/v1/secret/data/auth/'.$this->owner->id][1] = ['totp' => $totp->getSecret()];
        $this->withSession(['pending_user' => $this->owner->id, 'pending_at' => time(), 'pending_revision' => $this->owner->credentialRevision()])->post('/mfa', ['code' => $totp->now()])->assertRedirect('/');
        $this->app['auth']->logout();
        $this->withSession(['pending_user' => $this->owner->id, 'pending_at' => time(), 'pending_revision' => $this->owner->credentialRevision()])->post('/mfa',['code' => $totp->now()])->assertUnauthorized();
    }
}
