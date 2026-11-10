<?php

namespace Tests;

use App\Models\User;
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
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
                $version = (int) ($query['version'] ?? count($this->backend[$path] ?? []));

                return isset($this->backend[$path][$version]) ? Http::response(['data' => ['data' => $this->backend[$path][$version]]]) : Http::response([], 404);
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
        $this->actingAs($this->owner)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time()]);
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
        $this->withToken($token)->getJson('/api/v1/catalog')->assertExactJson(['entries' => []]);
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
        $id = $this->entry();
        $other = User::create(['id' => (string) Str::uuid(), 'name' => 'Other', 'email' => 'other@example.test', 'password' => 'unused']);
        $this->actingAs($other)->get('/entries/'.$id)->assertNotFound();
        $this->actingAs($this->owner)->post('/workspaces/'.$this->workspace.'/members', ['email' => $this->owner->email, 'name' => 'Owner', 'role' => 'remove'])->assertConflict();
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
        $this->withToken($token)->getJson('/api/v1/catalog')->assertExactJson(['entries' => []]);
        $this->post('/entries/'.$id.'/destroy', ['confirmation' => 'DELETE'])->assertConflict();
    }

    public function test_metadata_type_change_and_machine_owner_reveal_are_denied(): void
    {
        $id = $this->entry();
        [$machine,$token] = $this->machine($id);
        $this->post('/entries/'.$id, ['environment_id' => $this->environment, 'name' => 'Name', 'alias' => 'alias', 'type' => 'login', 'secret' => '{"username":"u","password":"p"}'])->assertUnprocessable();
        $this->app['auth']->logout();
        $this->withToken($token)->postJson('/entries/'.$id.'/reveal')->assertRedirect('/login');
    }

    public function test_mfa_replay_is_rejected(): void
    {
        $this->app['auth']->logout();
        $totp = TOTP::generate();
        $this->backend['/v1/secret/data/auth/'.$this->owner->id][1] = ['totp' => $totp->getSecret()];
        $this->withSession(['pending_user' => $this->owner->id, 'pending_at' => time()])->post('/mfa', ['code' => $totp->now()])->assertRedirect('/');
        $this->app['auth']->logout();
        $this->withSession(['pending_user' => $this->owner->id, 'pending_at' => time()])->post('/mfa',['code' => $totp->now()])->assertUnauthorized();
    }
}
