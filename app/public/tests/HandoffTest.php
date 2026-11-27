<?php

namespace Tests;

use App\Models\User;
use App\Vault\Catalog;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** storage_state: executor identities, entry types, limits, the lease and its release, audit without values. */
final class HandoffTest extends TestCase
{
    private string $temp;

    private array $backend = [];

    private string $workspace;

    private string $environment;

    private string $project;

    private string $password = 'synthetic-handoff-password';

    /** What the fake broker answers for GET /operations/{id}. */
    private string $brokerState = 'running';

    /** Whether the fake broker says the file is ready (group-readable) once the application recorded it. */
    private bool $brokerReady = true;

    /** Whether the fake broker knows the operation (false: not registered yet, or lost in a restart). */
    private bool $brokerKnows = true;

    /** Whether the fake broker confirms a file gone on cancel. */
    private bool $brokerRemoved = true;

    /** Whether the fake broker answers cancel with a 503 (not reachable for it). */
    private bool $brokerCancelDown = false;

    /** The fake broker's reason for a failed operation (GET /operations/{id}), when it gives one. */
    private ?string $brokerReason = null;

    /** How the fake broker answers POST /operations: 200, another status, or 'refused' / 'timeout' (no answer). */
    private int|string $brokerStart = 200;

    /** @var list<string> broker calls, method and path */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
        $this->temp = sys_get_temp_dir().'/vault-handoff-'.bin2hex(random_bytes(8));
        mkdir($this->temp, 0700);
        file_put_contents($this->temp.'/bao.json', '{"role_id":"test","secret_id":"test"}');
        file_put_contents($this->temp.'/broker.key', 'test-broker');
        file_put_contents($this->temp.'/bootstrap.code', 'test-bootstrap');
        $this->connectors(['shop.session' => ['kind' => 'storage_state', 'url' => 'https://shop.invalid/login', 'username_selector' => '#u', 'password_selector' => '#p',
            'submit_selector' => '#s', 'success_selector' => '#ok', 'success_text' => 'ok', 'entry_types' => ['login'], 'ttl_seconds' => 900],
            'test.http' => ['kind' => 'http', 'url' => 'https://example.invalid/status']]);
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
                    $this->backend[$path][$current + 1] = $request['data'];

                    return Http::response(['data' => ['version' => $current + 1]]);
                }
                $latest = $this->backend[$path] ?? [];

                return $latest ? Http::response(['data' => ['data' => end($latest), 'metadata' => ['version' => count($latest)]]]) : Http::response([], 404);
            }
            if (str_starts_with($path, '/operations')) {
                $this->calls[] = $request->method().' '.$path;
                if ($path === '/operations' && $this->brokerStart !== 200) {
                    if (is_int($this->brokerStart)) {
                        return Http::response(['detail' => 'refused'], $this->brokerStart);
                    }
                    throw new \Illuminate\Http\Client\ConnectionException(match ($this->brokerStart) {
                        'refused' => 'cURL error 7: Failed to connect to broker port 8010: Connection refused',
                        'unresolved' => 'cURL error 6: Could not resolve host: broker',
                        'connect-timeout' => 'cURL error 28: Connection timed out after 2001 milliseconds',
                        default => 'cURL error 28: Operation timed out after 8001 milliseconds with 0 bytes received',
                    });
                }
                if ($this->brokerCancelDown && str_ends_with($path, '/cancel')) {
                    return Http::response([], 503);
                }
                // The broker's file is ready once the application recorded it (the fake publishes at once).
                $written = DB::table('operations')->where('id', basename($path))->value('handoff') === 'written';

                return Http::response(match (true) {
                    str_ends_with($path, '/release') => ['state' => 'succeeded', 'removed' => true],
                    str_ends_with($path, '/cancel') => ['state' => 'revoked', 'removed' => $this->brokerRemoved],
                    $request->method() === 'POST' => ['state' => 'running'],
                    ! $this->brokerKnows => ['state' => 'failed', 'known' => false, 'reason' => 'restart'],
                    default => ['state' => $this->brokerState, 'known' => true] + ($this->brokerReason ? ['reason' => $this->brokerReason] : []) + ($written && $this->brokerReady && $this->brokerState === 'running' ? ['ready' => true, 'lease_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 900)] : []),
                });
            }

            return Http::response([], 503);
        });
        $owner = User::create(['id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('Synthetic-password-123!'), 'mfa_enabled' => true]);
        $this->workspace = (string) Str::uuid();
        $this->project = (string) Str::uuid();
        $this->environment = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $this->workspace, 'name' => 'Test']);
        DB::table('members')->insert(['workspace_id' => $this->workspace, 'user_id' => $owner->id, 'role' => 'owner']);
        DB::table('projects')->insert(['id' => $this->project, 'workspace_id' => $this->workspace, 'name' => 'Shop']);
        DB::table('environments')->insert(['id' => $this->environment, 'workspace_id' => $this->workspace, 'name' => 'stage']);
        $this->actingAs($owner)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time(), 'auth_revision' => $owner->credentialRevision()]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp.'/*') as $f) {
            unlink($f);
        }
        rmdir($this->temp);
        parent::tearDown();
    }

    private function connectors(array $actions): void
    {
        file_put_contents($this->temp.'/connectors.json', json_encode(['actions' => $actions]));
    }

    private function entry(string $type = 'login', string $alias = 'shop-customer'): string
    {
        $secret = $type === 'login' ? ['username' => 'synthetic-user', 'password' => $this->password] : ['token' => $this->password];

        return app(Catalog::class)->create(['project_id' => $this->project, 'environment_id' => $this->environment, 'name' => 'Private '.$alias,
            'alias' => $alias, 'type' => $type, 'secret' => json_encode($secret)]);
    }

    /** @return array{0: string, 1: string} machine ID and token */
    private function identity(bool $executor, string $name = 'runner'): array
    {
        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        DB::table('machines')->insert(['id' => $id, 'workspace_id' => $this->workspace, 'name' => $name, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(), 'executor' => $executor]);

        return [$id, $token];
    }

    private function grant(string $machine, string $entry, string $action = 'shop.session', bool $approval = false): string
    {
        $id = (string) Str::uuid();
        DB::table('grants')->insert(['id' => $id, 'machine_id' => $machine, 'entry_id' => $entry, 'action' => $action, 'approval' => $approval, 'expires_at' => now()->addHour()]);

        return $id;
    }

    private function execute(string $token, string $entry, string $request = 'nightly-run-0001')
    {
        return $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $entry, 'action' => 'shop.session', 'request_id' => $request]);
    }

    private function written(string $op, int $seconds = 900)
    {
        return $this->withToken('test-broker')->postJson('/api/internal/operations/'.$op.'/handoff',
            ['event' => 'written', 'lease_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $seconds)]);
    }

    private function assertNoValue(string $text): void
    {
        foreach ([$this->password, base64_encode($this->password), rawurlencode($this->password), bin2hex($this->password), '/handoff', '.json'] as $value) {
            $this->assertStringNotContainsString($value, $text);
        }
    }

    public function test_only_an_executor_may_be_granted_a_handoff(): void
    {
        $entry = $this->entry();
        [$agent] = $this->identity(false, 'llm-agent');
        [$executor] = $this->identity(true);
        $url = '/workspaces/'.$this->workspace.'/grants';
        $this->post($url, ['machine_id' => $agent, 'entry_id' => $entry, 'action' => 'shop.session', 'days' => 1])->assertStatus(303)->assertSessionHasErrors('action');
        $this->assertFalse(DB::table('grants')->where('machine_id', $agent)->exists());
        // A standing grant without approval: runs are unattended.
        $this->post($url, ['machine_id' => $executor, 'entry_id' => $entry, 'action' => 'shop.session', 'days' => 30])->assertRedirect($url);
        $this->assertFalse((bool) DB::table('grants')->where('machine_id', $executor)->value('approval'));
    }

    public function test_entry_type_outside_the_connector_list_is_refused(): void
    {
        [$executor, $token] = $this->identity(true);
        $other = $this->entry('api_key', 'shop-api');
        $this->post('/workspaces/'.$this->workspace.'/grants', ['machine_id' => $executor, 'entry_id' => $other, 'action' => 'shop.session', 'days' => 1])
            ->assertStatus(303)->assertSessionHasErrors('entry_id');
        // A grant that got there anyway (an older connector) still does not run.
        $this->grant($executor, $other);
        $this->execute($token, $other)->assertStatus(422);
        $this->assertSame(0, DB::table('operations')->count());
    }

    public function test_agent_identity_never_runs_a_handoff_even_with_a_grant(): void
    {
        $entry = $this->entry();
        [$agent, $token] = $this->identity(false);
        $this->grant($agent, $entry);
        $this->execute($token, $entry)->assertNotFound()->assertExactJson(['error' => 'not_found']);
        $this->assertSame(0, DB::table('operations')->count());
        // Someone else's operation is not found either, and cannot be released.
        [$executor, $executorToken] = $this->identity(true, 'runner-2');
        $this->grant($executor, $entry);
        $op = $this->execute($executorToken, $entry)->assertOk()->json('id');
        $this->withToken($token)->postJson('/api/v1/operations/'.$op.'/release')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertNotFound();
    }

    public function test_executor_and_one_time_links_are_separate_identities(): void
    {
        $this->post('/workspaces/'.$this->workspace.'/machines', ['name' => 'runner', 'days' => 7, 'executor' => '1', 'links' => '1'])->assertStatus(303)->assertSessionHasErrors('executor');
        $this->post('/workspaces/'.$this->workspace.'/machines', ['name' => 'runner', 'days' => 7, 'executor' => '1'])->assertOk();
        $this->assertTrue((bool) DB::table('machines')->where('name', 'runner')->value('executor'));
        $this->assertTrue(DB::table('audit')->where('event', 'machine.executor')->exists());
        $this->get('/workspaces/'.$this->workspace.'/agents')->assertOk()->assertSee('executor');
    }

    public function test_lease_ready_release_and_audit_without_values(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $first = $this->execute($token, $entry)->assertOk();
        $op = $first->json('id');
        $this->assertSame('running', $first->json('state'));
        $this->assertFalse($first->json('ready'));
        $this->assertNull($first->json('lease_expires_at'));
        $this->assertSame(['POST /operations'], $this->calls);
        // The claim covers login and the whole lease.
        $this->assertGreaterThanOrEqual(time() + 1000, strtotime(DB::table('operations')->where('id', $op)->value('expires_at')));
        // The same request_id never makes a second operation or a second dispatch.
        $this->execute($token, $entry)->assertOk()->assertJsonPath('id', $op);
        $this->assertSame(1, DB::table('operations')->count());
        $this->assertSame(1, count(array_filter($this->calls, fn ($c) => $c === 'POST /operations')));
        $this->withToken($token)->postJson('/api/v1/operations/'.$op.'/release')->assertStatus(409);

        $this->withToken('wrong')->postJson('/api/internal/operations/'.$op.'/handoff', ['event' => 'written', 'lease_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 900)])->assertUnauthorized();
        $this->written($op)->assertOk()->assertExactJson(['ok' => true]);
        $this->written($op)->assertStatus(409);
        $ready = $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('ready', true)->assertJsonPath('state', 'running');
        $this->assertNotNull($ready->json('lease_expires_at'));
        $this->assertSame(['id', 'state', 'ok', 'expires_at', 'ready', 'lease_expires_at'], array_keys($ready->json()));
        $released = $this->withToken($token)->postJson('/api/v1/operations/'.$op.'/release')->assertOk()->assertJsonPath('state', 'succeeded')->assertJsonPath('ready', false);
        $this->assertContains('POST /operations/'.$op.'/release', $this->calls);
        $this->withToken($token)->postJson('/api/v1/operations/'.$op.'/release')->assertOk()->assertJsonPath('state', 'succeeded');

        $audit = DB::table('audit')->where('subject', $op)->orderBy('id')->get(['event', 'outcome']);
        $this->assertSame([['operation.request', 'ok'], ['handoff.written', 'ok'], ['operation.finish', 'succeeded'], ['handoff.removed', 'release']],
            $audit->map(fn ($a) => [$a->event, $a->outcome])->all());
        $this->assertNoValue($first->getContent().$ready->getContent().$released->getContent().json_encode(DB::table('audit')->get()).json_encode(DB::table('operations')->get()));
        $this->get('/workspaces/'.$this->workspace.'/audit')->assertOk()->assertSee('Login session handed to executor')->assertDontSee($this->password);
    }

    public function test_broker_reports_ttl_and_restart(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        foreach (['ttl' => 'expired', 'restart' => 'failed', 'revoke' => 'revoked'] as $reason => $state) {
            $op = $this->execute($token, $entry, 'run-'.$reason.'-0001')->assertOk()->json('id');
            $this->written($op)->assertOk();
            $this->withToken('test-broker')->postJson('/api/internal/operations/'.$op.'/handoff', ['event' => 'removed', 'reason' => $reason])->assertOk();
            $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', $state)->assertJsonPath('ready', false);
            $this->assertSame($reason, DB::table('audit')->where('subject', $op)->where('event', 'handoff.removed')->value('outcome'));
        }
        $this->withToken('test-broker')->postJson('/api/internal/operations/'.$op.'/handoff', ['event' => 'removed', 'reason' => 'other'])->assertStatus(422);
        $this->withToken('test-broker')->postJson('/api/internal/operations/'.$op.'/handoff', ['event' => 'removed', 'reason' => 'ttl', 'value' => 'x'])->assertStatus(422);
        $this->assertSame(3, DB::table('audit')->where('event', 'handoff.removed')->count());
    }

    public function test_revoked_grant_ends_the_lease_and_a_late_write_is_refused(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $grant = $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->assertOk()->json('id');
        $this->written($op)->assertOk();
        $this->post('/workspaces/'.$this->workspace.'/revoke/grants/'.$grant)->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'revoked')->assertJsonPath('ready', false);
        // The broker's reaper removes the file within a second and reports it.
        $this->withToken('test-broker')->postJson('/api/internal/operations/'.$op.'/handoff', ['event' => 'removed', 'reason' => 'revoke'])->assertOk();
        $this->assertSame('revoke', DB::table('audit')->where('subject', $op)->where('event', 'handoff.removed')->value('outcome'));
        $this->withToken('test-broker')->getJson('/internal/operations/'.$op)->assertExactJson(['allowed' => false]);

        // Revoked before the file was written: the broker's write report is refused, so it removes the file at once.
        $grant = $this->grant($executor, $this->entry('login', 'shop-admin'));
        $second = $this->execute($token, DB::table('grants')->where('id', $grant)->value('entry_id'), 'nightly-run-0002')->assertOk()->json('id');
        DB::table('grants')->where('id', $grant)->update(['revoked_at' => now()]);
        $this->written($second)->assertStatus(409);
        $this->assertFalse(DB::table('audit')->where('subject', $second)->where('event', 'handoff.written')->exists());
    }

    public function test_one_live_handoff_per_entry_and_sixty_an_hour(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        [$other, $otherToken] = $this->identity(true, 'runner-2');
        $this->grant($executor, $entry);
        $this->grant($other, $entry);
        $op = $this->execute($token, $entry)->assertOk()->json('id');
        $this->execute($token, $entry, 'nightly-run-0002')->assertStatus(429);
        $this->execute($otherToken, $entry, 'nightly-run-0003')->assertStatus(429);
        DB::table('operations')->where('id', $op)->update(['state' => 'succeeded']);
        $this->execute($otherToken, $entry, 'nightly-run-0004')->assertOk();

        $hourly = $this->entry('login', 'shop-admin');
        [$busy, $busyToken] = $this->identity(true, 'runner-3');
        $this->grant($busy, $hourly);
        foreach (range(1, 60) as $i) {
            DB::table('operations')->insert(['id' => (string) Str::uuid(), 'machine_id' => $busy, 'grant_id' => (string) Str::uuid(), 'entry_id' => $hourly,
                'action' => 'shop.session', 'version' => 1, 'state' => 'succeeded', 'request_id' => 'old-run-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'expires_at' => now(), 'created_at' => now()->subMinutes(30), 'updated_at' => now()]);
        }
        $this->execute($busyToken, $hourly, 'nightly-run-0005')->assertStatus(429);
        DB::table('operations')->where('machine_id', $busy)->update(['created_at' => now()->subMinutes(61)]);
        $this->execute($busyToken, $hourly, 'nightly-run-0006')->assertOk();
    }

    public function test_release_tool_only_for_executors_and_ttl_limit(): void
    {
        [, $agentToken] = $this->identity(false, 'llm-agent');
        [$executor, $token] = $this->identity(true);
        $tools = fn ($t) => array_column($this->withToken($t)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'), 'name');
        $this->assertNotContains('vault_release', $tools($agentToken));
        $this->assertContains('vault_release', $tools($token));
        $entry = $this->entry();
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'vault_release', 'arguments' => ['id' => $op]]])
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->assertSame('succeeded', DB::table('operations')->where('id', $op)->value('state'));

        $this->connectors(['shop.session' => ['kind' => 'storage_state', 'url' => 'https://shop.invalid/login', 'entry_types' => ['login'], 'ttl_seconds' => 1801]]);
        $this->post('/workspaces/'.$this->workspace.'/grants', ['machine_id' => $executor, 'entry_id' => $this->entry('login', 'shop-admin'), 'action' => 'shop.session', 'days' => 1])
            ->assertStatus(303)->assertSessionHasErrors('action');
        $this->execute($token, $entry, 'nightly-run-0009')->assertStatus(503);
    }

    public function test_lease_report_must_fit_the_operation_deadline(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op, 3600)->assertStatus(422);
        $this->written($op, -5)->assertStatus(422);
        $this->withToken('test-broker')->postJson('/api/internal/operations/'.(string) Str::uuid().'/handoff', ['event' => 'written', 'lease_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)])->assertNotFound();
        $this->assertNull(DB::table('operations')->where('id', $op)->value('handoff'));
    }

    private function linksReady(): void
    {
        DB::table('link_settings')->insert(['workspace_id' => $this->workspace, 'region' => 'eu', 'email' => 'links@example.test',
            'default_lifetime' => 3600, 'max_lifetime' => 86400, 'token_saved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_existing_executor_cannot_be_given_one_time_links_later(): void
    {
        $this->linksReady();
        [$executor, $token] = $this->identity(true);
        $this->get('/workspaces/'.$this->workspace.'/agents')->assertOk()->assertSee('not for executors')
            ->assertDontSee('Allow one-time links for agent runner');
        $this->post('/workspaces/'.$this->workspace.'/machines/'.$executor.'/links', ['links' => '1'])->assertStatus(409);
        $this->assertFalse((bool) DB::table('machines')->where('id', $executor)->value('links'));
        $this->post('/workspaces/'.$this->workspace.'/grants', ['machine_id' => $executor, 'entry_id' => $this->entry(), 'action' => 'onetimesecret.link', 'days' => 1, 'approval' => '1'])
            ->assertStatus(303)->assertSessionHasErrors('action');
        // An executor that has the flag anyway (set before this check existed) still gets no link tool or link.
        DB::table('machines')->where('id', $executor)->update(['links' => true]);
        $tools = array_column($this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'), 'name');
        $this->assertSame([], array_values(array_filter($tools, fn ($t) => str_starts_with($t, 'vault_link_'))));
        $this->withToken($token)->getJson('/api/v1/links/options')->assertOk()->assertJsonPath('allowed', false);
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'hello', 'request_id' => 'link-run-0001'])->assertForbidden();
        $this->assertSame(0, DB::table('links')->count());
        // Stopping stays possible.
        $this->post('/workspaces/'.$this->workspace.'/machines/'.$executor.'/links', ['links' => '0'])->assertRedirect();
    }

    public function test_catalog_hides_a_handoff_action_from_an_agent_with_an_old_grant(): void
    {
        $entry = $this->entry();
        [$agent, $token] = $this->identity(false);
        [$executor, $executorToken] = $this->identity(true, 'runner-2');
        $this->grant($agent, $entry);
        $this->grant($agent, $entry, 'test.http');
        $this->grant($executor, $entry);
        $this->assertSame(['test.http'], array_column($this->withToken($token)->getJson('/api/v1/catalog')->assertOk()->json('entries'), 'action'));
        $this->assertSame(['shop.session'], array_column($this->withToken($executorToken)->getJson('/api/v1/catalog')->assertOk()->json('entries'), 'action'));
    }

    public function test_a_connector_with_mfa_cannot_be_granted_or_run(): void
    {
        [$executor, $token] = $this->identity(true);
        $entry = $this->entry();
        $this->grant($executor, $entry);
        $this->connectors(['shop.session' => ['kind' => 'storage_state', 'url' => 'https://shop.invalid/login', 'entry_types' => ['login'], 'mfa_selector' => '#code']]);
        $this->post('/workspaces/'.$this->workspace.'/grants', ['machine_id' => $executor, 'entry_id' => $this->entry('login', 'shop-admin'), 'action' => 'shop.session', 'days' => 1])
            ->assertStatus(303)->assertSessionHasErrors('action');
        $this->execute($token, $entry)->assertStatus(503);
        $this->assertSame(0, DB::table('operations')->count());
    }

    public function test_authorization_requires_an_executor_and_an_accepted_entry_type_during_the_lease(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->assertOk()->json('id');
        $check = fn () => $this->withToken('test-broker')->getJson('/internal/operations/'.$op)->json('allowed');
        $this->assertTrue($check());
        DB::table('machines')->where('id', $executor)->update(['executor' => false]);
        $this->assertFalse($check());
        DB::table('machines')->where('id', $executor)->update(['executor' => true]);
        $this->assertTrue($check());
        $this->connectors(['shop.session' => ['kind' => 'storage_state', 'url' => 'https://shop.invalid/login', 'entry_types' => ['prestashop_admin'], 'ttl_seconds' => 900]]);
        $this->assertFalse($check());
    }

    public function test_a_broker_refusal_before_start_fails_the_operation_at_once(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        // A stopped broker container is not in Docker's DNS (cURL 6); a connect timeout sent nothing either.
        foreach ([[429, 429], [403, 503], [503, 503], ['refused', 503], ['unresolved', 503], ['connect-timeout', 503]] as $i => [$answer, $status]) {
            $this->brokerStart = $answer;
            $this->execute($token, $entry, 'refused-run-000'.$i)->assertStatus($status);
            $op = DB::table('operations')->where('request_id', 'refused-run-000'.$i)->first();
            $this->assertSame('failed', $op->state, (string) $answer);
            $this->assertSame('operation.finish', DB::table('audit')->where('subject', $op->id)->orderByDesc('id')->value('event'));
        }
        // Nothing holds the entry: the next run starts. A timeout after sending is ambiguous and stays running.
        $this->brokerStart = 'timeout';
        $this->execute($token, $entry, 'timeout-run-0001')->assertStatus(503);
        $this->assertSame('running', DB::table('operations')->where('request_id', 'timeout-run-0001')->value('state'));
        $this->brokerStart = 200;
        DB::table('operations')->where('request_id', 'timeout-run-0001')->update(['state' => 'failed']);
        $this->execute($token, $entry, 'normal-run-0001')->assertOk()->assertJsonPath('state', 'running');
    }

    public function test_ready_waits_for_the_broker_and_lease_end_is_rfc3339(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        // Recorded here, but the broker has not made the file readable (or it ended): not ready, no release.
        $this->brokerState = 'requires_user_action';
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('ready', false)->assertJsonPath('lease_expires_at', null);
        $this->withToken($token)->postJson('/api/v1/operations/'.$op.'/release')->assertStatus(409);
        $this->brokerState = 'running';
        $lease = $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('ready', true)->json('lease_expires_at');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $lease);
        $this->assertEqualsWithDelta(time() + 900, strtotime($lease), 5);
    }

    public function test_a_foreign_executor_neither_reads_nor_releases(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        [, $foreign] = $this->identity(true, 'runner-2');
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        $this->withToken($foreign)->getJson('/api/v1/operations/'.$op)->assertNotFound();
        $this->withToken($foreign)->postJson('/api/v1/operations/'.$op.'/release')->assertNotFound();
        $this->withToken($foreign)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'vault_release', 'arguments' => ['id' => $op]]])
            ->assertOk()->assertJsonMissingPath('result')->assertJsonPath('error.message', 'Action unavailable or refused');
        $this->assertNotContains('POST /operations/'.$op.'/release', $this->calls);
        $this->assertSame('running', DB::table('operations')->where('id', $op)->value('state'));
    }

    public function test_removal_is_recorded_only_when_the_broker_confirms_it_and_maintenance_retries(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        $this->brokerRemoved = false;
        $this->withToken($token)->postJson('/api/v1/operations/'.$op.'/cancel')->assertOk()->assertJsonPath('state', 'revoked');
        $this->assertSame('written', DB::table('operations')->where('id', $op)->value('handoff'));
        $this->assertFalse(DB::table('audit')->where('subject', $op)->where('event', 'handoff.removed')->exists());
        Artisan::call('vault:maintenance');
        $this->assertSame('written', DB::table('operations')->where('id', $op)->value('handoff'));
        $this->brokerRemoved = true;
        Artisan::call('vault:maintenance');
        $this->assertSame('removed', DB::table('operations')->where('id', $op)->value('handoff'));
        $this->assertSame('revoke', DB::table('audit')->where('subject', $op)->where('event', 'handoff.removed')->value('outcome'));
        // A lease past its end: maintenance expires it and records the confirmed removal.
        $second = $this->execute($token, $entry, 'nightly-run-0002')->json('id');
        $this->written($second)->assertOk();
        DB::table('operations')->where('id', $second)->update(['expires_at' => now()->subSecond()]);
        Artisan::call('vault:maintenance');
        $this->assertSame(['expired', 'removed'], array_values((array) DB::table('operations')->where('id', $second)->first(['state', 'handoff'])));
        $this->assertSame('ttl', DB::table('audit')->where('subject', $second)->where('event', 'handoff.removed')->value('outcome'));
    }

    public function test_ready_needs_the_brokers_confirmation_even_while_it_runs(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        // Recorded here and running there, but the broker has not made the file group-readable yet.
        $this->brokerReady = false;
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'running')->assertJsonPath('ready', false);
        $this->withToken($token)->postJson('/api/v1/operations/'.$op.'/release')->assertStatus(409);
        $this->assertNotContains('POST /operations/'.$op.'/release', $this->calls);
    }

    public function test_an_unconfirmed_removal_keeps_the_entry_reserved(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        // The broker could not remove the file: the operation ends, its file may still be readable.
        $this->brokerRemoved = false;
        $this->withToken($token)->postJson('/api/v1/operations/'.$op.'/cancel')->assertOk()->assertJsonPath('state', 'revoked');
        DB::table('operations')->where('id', $op)->update(['state' => 'failed', 'expires_at' => now()->subMinute()]);
        $this->execute($token, $entry, 'nightly-run-0002')->assertStatus(429);
        // Confirmed gone: the entry is free again.
        $this->withToken('test-broker')->postJson('/api/internal/operations/'.$op.'/handoff', ['event' => 'removed', 'reason' => 'error'])->assertOk();
        $this->execute($token, $entry, 'nightly-run-0003')->assertOk();
    }

    public function test_a_start_still_on_its_way_is_not_failed_by_a_concurrent_status(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        // As if the claim had just happened and the broker had not registered the start yet.
        DB::table('operations')->where('id', $op)->update(['dispatched_at' => null]);
        $this->brokerKnows = false;
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'running');
        $this->assertSame('running', DB::table('operations')->where('id', $op)->value('state'));
        // Long after the claim without a confirmed start: lost.
        DB::table('operations')->where('id', $op)->update(['updated_at' => now()->subMinute()]);
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'failed');
        // A confirmed start the broker no longer knows (a restart): failed at once.
        $second = $this->execute($token, $entry, 'nightly-run-0002')->json('id');
        $this->assertNotNull(DB::table('operations')->where('id', $second)->value('dispatched_at'));
        $this->withToken($token)->getJson('/api/v1/operations/'.$second)->assertOk()->assertJsonPath('state', 'failed');
    }

    public function test_the_operation_ends_with_its_lease_and_times_are_rfc3339(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op, 60)->assertOk();
        $row = DB::table('operations')->where('id', $op)->first();
        $this->assertEqualsWithDelta(time() + 70, strtotime($row->expires_at.' UTC'), 3);
        $this->assertEqualsWithDelta(time() + 60, strtotime($row->lease_expires_at.' UTC'), 3);
        $answer = $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk();
        foreach (['expires_at', 'lease_expires_at'] as $key) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $answer->json($key), $key);
        }
    }

    public function test_the_broker_learns_only_the_names_of_secret_fields(): void
    {
        DB::table('entry_types')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace, 'key' => 'shop_admin', 'name' => 'Shop admin',
            'fields' => json_encode([['key' => 'username', 'label' => 'User', 'kind' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Password', 'kind' => 'secret', 'required' => true],
                ['key' => 'url', 'label' => 'Admin URL', 'kind' => 'text', 'required' => false],
                ['key' => 'recovery', 'label' => 'Recovery codes', 'kind' => 'multiline', 'required' => false]]),
            'created_at' => now(), 'updated_at' => now()]);
        $this->connectors(['shop.session' => ['kind' => 'storage_state', 'url' => 'https://shop.invalid/login', 'entry_types' => ['shop_admin'], 'ttl_seconds' => 900]]);
        $entry = app(Catalog::class)->create(['project_id' => $this->project, 'environment_id' => $this->environment, 'name' => 'Admin', 'alias' => 'shop-admin',
            'type' => 'shop_admin', 'secret' => json_encode(['username' => 'u', 'password' => $this->password, 'url' => 'https://shop.example/admin123', 'recovery' => 'r'])]);
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->assertOk()->json('id');
        $answer = $this->withToken('test-broker')->getJson('/internal/operations/'.$op)->assertOk();
        $this->assertSame(['password', 'recovery'], $answer->json('secret_fields'));
        $this->assertNoValue($answer->getContent());
    }

    public function test_a_removed_report_for_a_file_never_recorded_is_harmless(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        // `written` was sent but refused or never answered: the broker still reports the removal.
        $this->withToken('test-broker')->postJson('/api/internal/operations/'.$op.'/handoff', ['event' => 'removed', 'reason' => 'ttl'])->assertOk();
        $this->withToken('test-broker')->postJson('/api/internal/operations/'.$op.'/handoff', ['event' => 'removed', 'reason' => 'ttl'])->assertOk();
        $this->assertSame(['expired', null], array_values((array) DB::table('operations')->where('id', $op)->first(['state', 'handoff'])));
        $this->assertFalse(DB::table('audit')->where('subject', $op)->where('event', 'handoff.removed')->exists());
    }

    public function test_a_lost_final_event_after_a_broker_restart_is_settled_by_the_next_status(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        // The broker restarted (or the host rebooted): it no longer knows the operation, and its final event is lost.
        $this->brokerKnows = false;
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'failed');
        $this->assertContains('POST /operations/'.$op.'/cancel', $this->calls);
        $this->assertSame('removed', DB::table('operations')->where('id', $op)->value('handoff'));
        $this->assertSame(['failed', 'restart'], [DB::table('audit')->where('subject', $op)->where('event', 'operation.finish')->value('outcome'),
            DB::table('audit')->where('subject', $op)->where('event', 'operation.failure')->value('outcome')]);
        // The entry is free at once, not after the nightly maintenance.
        $this->brokerKnows = true;
        $this->execute($token, $entry, 'nightly-run-0002')->assertOk();
    }

    public function test_a_failure_the_broker_reports_removes_a_recorded_file(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        $this->brokerState = 'failed';
        $this->brokerReason = 'handoff';
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'failed');
        $this->assertContains('POST /operations/'.$op.'/cancel', $this->calls);
        $this->assertSame('removed', DB::table('operations')->where('id', $op)->value('handoff'));
        $this->assertSame('error', DB::table('audit')->where('subject', $op)->where('event', 'handoff.removed')->value('outcome'));
        $this->assertSame('handoff', DB::table('audit')->where('subject', $op)->where('event', 'operation.failure')->value('outcome'));
    }

    public function test_status_of_an_ended_handoff_with_its_file_still_recorded_settles_it(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        // Ended here, the broker's `removed` never arrived.
        DB::table('operations')->where('id', $op)->update(['state' => 'failed']);
        // A broker that cannot be reached, or cannot remove it yet: the answer is still the state, the file stays recorded.
        $this->brokerCancelDown = true;
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'failed');
        $this->brokerCancelDown = false;
        $this->brokerRemoved = false;
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'failed');
        $this->assertSame('written', DB::table('operations')->where('id', $op)->value('handoff'));
        $this->assertSame(2, count(array_filter($this->calls, fn ($c) => $c === 'POST /operations/'.$op.'/cancel')));
        $this->brokerRemoved = true;
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('ready', false);
        $this->assertSame('removed', DB::table('operations')->where('id', $op)->value('handoff'));
        $this->assertSame('error', DB::table('audit')->where('subject', $op)->where('event', 'handoff.removed')->value('outcome'));
    }

    public function test_vault_links_settles_a_lease_the_broker_lost(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        $this->written($op)->assertOk();
        // Nobody asks for the status: the every-10-minutes job finds it.
        Artisan::call('vault:links');
        $this->assertSame(['running', 'written'], array_values((array) DB::table('operations')->where('id', $op)->first(['state', 'handoff'])));
        $this->brokerKnows = false;
        Artisan::call('vault:links');
        $this->assertSame(['failed', 'removed'], array_values((array) DB::table('operations')->where('id', $op)->first(['state', 'handoff'])));
        // An ended operation whose removal was not confirmed is settled by the same job.
        $this->brokerKnows = true;
        $second = $this->execute($token, $entry, 'nightly-run-0002')->json('id');
        $this->written($second)->assertOk();
        DB::table('operations')->where('id', $second)->update(['state' => 'succeeded']);
        Artisan::call('vault:links');
        $this->assertSame('removed', DB::table('operations')->where('id', $second)->value('handoff'));
    }

    public function test_a_start_the_broker_later_knows_is_confirmed_so_a_restart_is_not_hidden(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        // The start's answer was lost (timeout after sending): not confirmed.
        $this->brokerStart = 'timeout';
        $this->execute($token, $entry)->assertStatus(503);
        $op = DB::table('operations')->where('request_id', 'nightly-run-0001')->value('id');
        $this->assertNull(DB::table('operations')->where('id', $op)->value('dispatched_at'));
        // The broker knows it: confirmed now.
        $this->brokerStart = 200;
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'running');
        $this->assertNotNull(DB::table('operations')->where('id', $op)->value('dispatched_at'));
        // A broker restart right after that is a failure at once, not 30 seconds of "running".
        $this->brokerKnows = false;
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'failed');
    }

    public function test_the_written_report_confirms_the_start_too(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->json('id');
        DB::table('operations')->where('id', $op)->update(['dispatched_at' => null]);
        $this->written($op)->assertOk();
        $this->assertNotNull(DB::table('operations')->where('id', $op)->value('dispatched_at'));
    }

    public function test_the_audit_says_why_an_operation_failed_with_a_fixed_code_only(): void
    {
        $entry = $this->entry();
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $this->brokerState = 'failed';
        $this->brokerReason = 'leak';
        $op = $this->execute($token, $entry)->json('id');
        $this->withToken($token)->getJson('/api/v1/operations/'.$op)->assertOk()->assertJsonPath('state', 'failed')->assertJsonMissingPath('reason');
        $this->assertSame('leak', DB::table('audit')->where('subject', $op)->where('event', 'operation.failure')->value('outcome'));
        $this->get('/workspaces/'.$this->workspace.'/audit')->assertOk()->assertSee('Why the request failed');
        // Anything outside the vocabulary is not recorded.
        $this->brokerReason = 'https://shop.invalid/?p='.$this->password;
        $second = $this->execute($token, $entry, 'nightly-run-0002')->json('id');
        $this->withToken($token)->getJson('/api/v1/operations/'.$second)->assertOk()->assertJsonPath('state', 'failed');
        $this->assertFalse(DB::table('audit')->where('subject', $second)->where('event', 'operation.failure')->exists());
        // The broker's final event carries the code too, and nothing else is accepted.
        $this->brokerState = 'running';
        $third = $this->execute($token, $entry, 'nightly-run-0003')->json('id');
        $this->written($third)->assertOk();
        $removed = fn (array $body) => $this->withToken('test-broker')->postJson('/api/internal/operations/'.$third.'/handoff', ['event' => 'removed', 'reason' => 'error'] + $body);
        $removed(['failure' => 'not-a-code'])->assertStatus(422);
        $removed(['failure' => 'state'])->assertOk();
        $this->assertSame('state', DB::table('audit')->where('subject', $third)->where('event', 'operation.failure')->value('outcome'));
        $this->assertNoValue(json_encode(DB::table('audit')->get()));
    }

    public function test_the_broker_learns_the_names_of_text_fields_too(): void
    {
        DB::table('entry_types')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace, 'key' => 'shop_admin', 'name' => 'Shop admin',
            'fields' => json_encode([['key' => 'username', 'label' => 'User', 'kind' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Password', 'kind' => 'secret', 'required' => true],
                ['key' => 'url', 'label' => 'Admin URL', 'kind' => 'text', 'required' => false]]),
            'created_at' => now(), 'updated_at' => now()]);
        $this->connectors(['shop.session' => ['kind' => 'storage_state', 'url' => 'https://shop.invalid/login', 'entry_types' => ['shop_admin'], 'ttl_seconds' => 900]]);
        $entry = app(Catalog::class)->create(['project_id' => $this->project, 'environment_id' => $this->environment, 'name' => 'Admin', 'alias' => 'shop-admin',
            'type' => 'shop_admin', 'secret' => json_encode(['username' => 'u', 'password' => $this->password, 'url' => 'https://shop.example/admin123'])]);
        [$executor, $token] = $this->identity(true);
        $this->grant($executor, $entry);
        $op = $this->execute($token, $entry)->assertOk()->json('id');
        $answer = $this->withToken('test-broker')->getJson('/internal/operations/'.$op)->assertOk();
        $this->assertSame(['username', 'url'], $answer->json('text_fields'));
        $this->assertNoValue($answer->getContent());
    }
}
