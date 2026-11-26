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

                return Http::response(match (true) {
                    str_ends_with($path, '/release') => ['state' => 'succeeded'],
                    str_ends_with($path, '/cancel') => ['state' => 'revoked'],
                    $request->method() === 'POST' => ['state' => 'running'],
                    default => ['state' => $this->brokerState],
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
}
