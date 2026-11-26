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

/** One-time links through Onetime Secret: setup, people, agents, approval, burn. Onetime Secret is faked. */
final class LinksTest extends TestCase
{
    private string $temp;

    /** OpenBao KV v2: path => [version => data]. */
    private array $backend = [];

    /** What Onetime Secret was sent, in order: [method, path, body, basic-auth user]. */
    private array $ots = [];

    /** The receipt state the fake returns. */
    private string $receiptState = 'new';

    /** The receipt state the fake reports after a burn. */
    private string $burnState = 'burned';

    private User $owner;

    private string $workspace;

    private string $environment;

    private string $base;

    private string $sentinel = 'synthetic-entry-password-never-to-agent';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        Artisan::call('migrate', ['--force' => true]);
        $this->temp = sys_get_temp_dir().'/vault-links-'.bin2hex(random_bytes(8));
        mkdir($this->temp, 0700);
        file_put_contents($this->temp.'/bao.json', '{"role_id":"test","secret_id":"test"}');
        file_put_contents($this->temp.'/broker.key', 'test-broker');
        file_put_contents($this->temp.'/connectors.json', json_encode(['actions' => ['test.http' => ['kind' => 'http', 'url' => 'https://example.invalid/status']]]));
        config(['vault.credentials' => $this->temp.'/bao.json', 'vault.broker_key' => $this->temp.'/broker.key',
            'vault.connectors' => $this->temp.'/connectors.json', 'vault.onetimesecret_origin' => 'https://%s.ots.test']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = parse_url($request->url());
            $path = $url['path'];
            if (str_ends_with($url['host'], '.ots.test')) {
                $auth = base64_decode(substr($request->header('Authorization')[0] ?? '', 6));
                $this->ots[] = [$request->method(), $path, $request->data(), strstr($auth, ':', true)];
                if ($auth !== 'links@example.test:synthetic-ots-token') {
                    return Http::response(['message' => 'Not authorized'], 401);
                }

                return match (true) {
                    $path === '/api/v2/secret/conceal' => Http::response(['record' => ['secret' => ['identifier' => 'sec'.count($this->ots).'abcdefgh'], 'receipt' => ['identifier' => 'rcpt'.count($this->ots).'abcdefgh']]]),
                    $path === '/api/v2/receipt/recent' => Http::response(['records' => []]),
                    str_ends_with($path, '/burn') => Http::response(['record' => ['state' => $this->burnState]]),
                    str_starts_with($path, '/api/v2/receipt/') => Http::response(['record' => ['state' => $this->receiptState]]),
                    default => Http::response([], 404),
                };
            }
            if ($path === '/v1/auth/approle/login') {
                return Http::response(['auth' => ['client_token' => 'test']]);
            }
            if (str_starts_with($path, '/v1/secret/metadata/')) {
                $data = '/v1/secret/data/'.substr($path, strlen('/v1/secret/metadata/'));
                if ($request->method() === 'DELETE') {
                    unset($this->backend[$data]);

                    return Http::response([], 204);
                }

                return isset($this->backend[$data]) ? Http::response(['data' => ['current_version' => max(array_keys($this->backend[$data])),
                    'versions' => array_map(fn () => ['destroyed' => false], $this->backend[$data])]]) : Http::response([], 404);
            }
            if (str_starts_with($path, '/v1/secret/destroy/')) {
                $data = '/v1/secret/data/'.substr($path, strlen('/v1/secret/destroy/'));
                foreach ($request['versions'] as $version) {
                    unset($this->backend[$data][$version]);
                }

                return Http::response([], 204);
            }
            if (str_starts_with($path, '/v1/secret/data/')) {
                if ($request->method() === 'POST') {
                    $current = $this->backend[$path] ?? [] ? max(array_keys($this->backend[$path])) : 0;
                    if ($request['options']['cas'] !== $current) {
                        return Http::response([], 400);
                    }
                    $this->backend[$path][$current + 1] = $request['data'];

                    return Http::response(['data' => ['version' => $current + 1]]);
                }
                parse_str($url['query'] ?? '', $query);
                $version = (int) ($query['version'] ?? ($this->backend[$path] ?? [] ? max(array_keys($this->backend[$path])) : 0));

                return isset($this->backend[$path][$version]) ? Http::response(['data' => ['data' => $this->backend[$path][$version], 'metadata' => ['version' => $version]]]) : Http::response([], 404);
            }

            return Http::response([], 503);
        });
        $this->owner = User::create(['id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('Synthetic-password-123!'), 'mfa_enabled' => true]);
        $this->workspace = (string) Str::uuid();
        $project = (string) Str::uuid();
        $this->environment = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $this->workspace, 'name' => 'Test']);
        DB::table('members')->insert(['workspace_id' => $this->workspace, 'user_id' => $this->owner->id, 'role' => 'owner']);
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $this->workspace, 'name' => 'Shop']);
        DB::table('environments')->insert(['id' => $this->environment, 'project_id' => $project, 'name' => 'stage']);
        $this->base = '/workspaces/'.$this->workspace;
        $this->actingAs($this->owner)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time(), 'auth_revision' => $this->owner->credentialRevision()]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp.'/*') as $f) {
            unlink($f);
        }
        rmdir($this->temp);
        parent::tearDown();
    }

    private function setUpAccount(): void
    {
        $this->post($this->base.'/onetimesecret', ['region' => 'eu', 'email' => 'links@example.test', 'token' => 'synthetic-ots-token',
            'default_lifetime' => 86400, 'max_lifetime' => 604800])->assertRedirect($this->base.'/onetimesecret');
    }

    private function entry(): string
    {
        return app(Catalog::class)->create(['environment_id' => $this->environment, 'name' => 'Shop admin', 'alias' => 'shop-admin', 'type' => 'login',
            'secret' => json_encode(['username' => 'admin', 'password' => $this->sentinel])]);
    }

    private function agent(bool $links = true): array
    {
        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        DB::table('machines')->insert(['id' => $id, 'workspace_id' => $this->workspace, 'name' => 'mailer', 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(), 'links' => $links]);

        return [$id, $token];
    }

    private function grant(string $machine, string $entry, bool $approval): void
    {
        DB::table('grants')->insert(['id' => (string) Str::uuid(), 'machine_id' => $machine, 'entry_id' => $entry, 'action' => 'onetimesecret.link',
            'approval' => $approval, 'expires_at' => now()->addHour()]);
    }

    private function conceals(): array
    {
        return array_values(array_filter($this->ots, fn ($call) => $call[1] === '/api/v2/secret/conceal'));
    }

    public function test_owner_sets_up_the_account_and_the_token_stays_in_openbao(): void
    {
        $this->get($this->base.'/onetimesecret')->assertOk()->assertSeeText('Set up the account');
        $this->get($this->base.'/links')->assertOk()->assertSeeText('Onetime Secret is not set up.')->assertSee('href="'.$this->base.'/onetimesecret"', false);
        $this->setUpAccount();
        $this->assertSame(['token' => 'synthetic-ots-token'], $this->backend['/v1/secret/data/settings/'.$this->workspace][1]);
        $row = DB::table('link_settings')->where('workspace_id', $this->workspace)->first();
        $this->assertSame('links@example.test', $row->email);
        $this->assertStringNotContainsString('synthetic-ots-token', json_encode($row));
        $this->get($this->base.'/onetimesecret')->assertOk()->assertSeeText('Change the account')->assertDontSee('synthetic-ots-token');
        // Saving again without a token keeps it.
        $this->post($this->base.'/onetimesecret', ['region' => 'eu', 'email' => 'links@example.test', 'default_lifetime' => 3600, 'max_lifetime' => 86400])->assertRedirect();
        $this->assertCount(1, $this->backend['/v1/secret/data/settings/'.$this->workspace]);
        $this->post($this->base.'/onetimesecret/check')->assertRedirect()->assertSessionHas('notice', 'Onetime Secret accepted the account.');
        $this->assertSame(['GET', '/api/v2/receipt/recent'], array_slice(end($this->ots), 0, 2));
        // A default longer than the longest is refused.
        $this->post($this->base.'/onetimesecret', ['region' => 'eu', 'email' => 'links@example.test', 'default_lifetime' => 604800, 'max_lifetime' => 86400])
            ->assertSessionHas('form_errors', fn ($e) => isset($e['default_lifetime']));
        $this->assertTrue(DB::table('audit')->where('event', 'links.settings')->exists());
    }

    public function test_roles_see_links_and_setup_as_allowed(): void
    {
        $this->setUpAccount();
        $this->get('/?workspace='.$this->workspace)->assertSee('href="'.$this->base.'/links"', false);
        $this->get($this->base.'/structure')->assertSee('href="'.$this->base.'/onetimesecret"', false);
        DB::table('members')->update(['role' => 'editor']);
        $this->get($this->base.'/links')->assertOk();
        $this->get($this->base.'/onetimesecret')->assertNotFound();
        $this->post($this->base.'/onetimesecret', [])->assertNotFound();
        $this->get($this->base.'/structure')->assertDontSee('href="'.$this->base.'/onetimesecret"', false);
        DB::table('members')->update(['role' => 'viewer']);
        $this->get($this->base.'/links')->assertNotFound();
        $this->post($this->base.'/links', ['text' => 'x'])->assertNotFound();
        $this->get('/?workspace='.$this->workspace)->assertDontSee('href="'.$this->base.'/links"', false);
    }

    public function test_a_person_shares_text_and_sees_the_link_once(): void
    {
        $this->setUpAccount();
        $page = $this->post($this->base.'/links', ['text' => 'wifi: synthetic-guest-pass', 'lifetime' => 3600, 'note' => 'guest wifi'])->assertOk();
        $page->assertSeeText('Copy the link now. It is shown only once.')->assertSee('https://eu.ots.test/secret/sec', false);
        $this->assertSame('no-store, private', $page->headers->get('Cache-Control'));
        [$method, , $body, $user] = $this->conceals()[0];
        $this->assertSame(['POST', 'links@example.test'], [$method, $user]);
        $this->assertSame(['secret' => ['secret' => 'wifi: synthetic-guest-pass', 'ttl' => 3600]], $body);
        $link = DB::table('links')->first();
        $this->assertSame(['active', 3600, 'guest wifi'], [$link->state, (int) $link->lifetime, $link->note]);
        $this->assertStringNotContainsString('synthetic-guest-pass', json_encode(DB::table('links')->get()));
        $this->assertStringNotContainsString('synthetic-guest-pass', json_encode(DB::table('audit')->get()));
        $this->get($this->base.'/links')->assertOk()->assertSeeText('guest wifi')->assertSeeText('not opened')
            ->assertDontSee('/secret/sec', false)->assertDontSee('synthetic-guest-pass');
    }

    public function test_an_entry_link_puts_the_values_in_and_a_failed_form_keeps_the_draft_out_of_the_session(): void
    {
        $this->setUpAccount();
        $entry = $this->entry();
        $this->get('/entries/'.$entry)->assertSee('href="'.$this->base.'/links?entry='.$entry.'#add-link"', false);
        $this->get($this->base.'/links?entry='.$entry)->assertOk()->assertSee('Username: {{username}}', false)->assertSee('name="entry_id" value="'.$entry.'"', false);

        $failed = $this->post($this->base.'/links', ['entry_id' => $entry, 'text' => "Hello\nLogin {{username}} / {{pin}}", 'lifetime' => 3600]);
        $failed->assertStatus(422)->assertSee('Login {{username}} / {{pin}}', false)->assertSeeText('The entry type has no field pin.');
        $this->assertSame([], $this->conceals());
        $this->assertStringNotContainsString('{{pin}}', json_encode(session()->all()));
        $this->assertNull(session('form_errors'));

        $this->post($this->base.'/links', ['entry_id' => $entry, 'text' => "Login {{ username }} / {{password}}", 'lifetime' => 2592000])
            ->assertStatus(422)->assertSeeText('Choose one of the lifetimes this workspace allows.');
        $this->post($this->base.'/links', ['entry_id' => $entry, 'text' => "Login {{ username }} / {{password}}", 'passphrase' => 'synthetic-phrase'])->assertOk();
        $this->assertSame(['secret' => ['secret' => 'Login admin / '.$this->sentinel, 'ttl' => 86400, 'passphrase' => 'synthetic-phrase']], $this->conceals()[0][2]);
        $link = DB::table('links')->first();
        $this->assertSame([$entry, ['username', 'password'], true], [$link->entry_id, json_decode($link->fields), (bool) $link->passphrase]);
        $stored = $this->backend['/v1/secret/data/links/'.$link->id];
        $this->assertSame('synthetic-phrase', end($stored)['passphrase']);
        $this->assertArrayNotHasKey('text', end($stored));
        // Without text the link holds every field, one per line.
        $this->post($this->base.'/links', ['entry_id' => $entry])->assertOk();
        $this->assertSame("Username: admin\nPassword: ".$this->sentinel, $this->conceals()[1][2]['secret']['secret']);
    }

    public function test_burn_uses_the_passphrase_and_ends_the_link(): void
    {
        $this->setUpAccount();
        $this->post($this->base.'/links', ['text' => 'synthetic', 'passphrase' => 'synthetic-phrase'])->assertOk();
        $link = DB::table('links')->first();
        $editor = User::create(['id' => (string) Str::uuid(), 'name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'unused', 'mfa_enabled' => true]);
        DB::table('members')->insert(['workspace_id' => $this->workspace, 'user_id' => $editor->id, 'role' => 'editor']);
        $this->actingAs($editor)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time(), 'auth_revision' => $editor->credentialRevision()]);
        $this->post($this->base.'/links/'.$link->id.'/burn')->assertNotFound();
        $this->actingAs($this->owner)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time(), 'auth_revision' => $this->owner->credentialRevision()]);
        $this->post($this->base.'/links/'.$link->id.'/burn')->assertRedirect()->assertSessionHas('notice', 'Link burned. It can no longer be opened.');
        $burn = end($this->ots);
        $this->assertSame(['POST', '/api/v2/receipt/rcpt1abcdefgh/burn', ['continue' => true, 'passphrase' => 'synthetic-phrase']], array_slice($burn, 0, 3));
        $this->assertSame('burned', DB::table('links')->value('state'));
        $this->assertArrayNotHasKey('/v1/secret/data/links/'.$link->id, $this->backend);
        $this->post($this->base.'/links/'.$link->id.'/burn')->assertRedirect()->assertSessionHas('notice', 'This link had already ended (burned); nothing was burned.');
    }

    public function test_check_now_follows_the_receipt(): void
    {
        $this->setUpAccount();
        $this->post($this->base.'/links', ['text' => 'synthetic'])->assertOk();
        $this->receiptState = 'revealed';
        $this->post($this->base.'/links/check')->assertRedirect()->assertSessionHas('notice', 'Checked 1 open link with Onetime Secret.');
        $this->assertSame('revealed', DB::table('links')->value('state'));
        $this->assertSame([], array_filter(array_keys($this->backend), fn ($p) => str_contains($p, '/links/')));
        $this->get($this->base.'/links')->assertSeeText('read');
    }

    public function test_an_agent_needs_the_owner_permission_and_gets_its_text_link(): void
    {
        $this->setUpAccount();
        [$machine, $token] = $this->agent(false);
        $this->withToken($token)->getJson('/api/v1/links/options')->assertExactJson(['allowed' => false, 'lifetimes' => [], 'default_lifetime' => null, 'entries' => []]);
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'synthetic', 'request_id' => 'link-request-1'])->assertForbidden();
        $this->post($this->base.'/machines/'.$machine.'/links', ['links' => '1'])->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/links/options')->assertJsonPath('allowed', true)->assertJsonPath('lifetimes', [3600, 86400, 259200, 604800]);
        $first = $this->withToken($token)->postJson('/api/v1/links', ['text' => 'temporary password: synthetic-generated', 'lifetime' => 3600, 'request_id' => 'link-request-1'])
            ->assertOk()->assertJsonPath('state', 'active')->assertJsonPath('link', 'https://eu.ots.test/secret/sec1abcdefgh');
        // A retry with the same request_id returns the same link and sends nothing again; a different ask is refused.
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'temporary password: synthetic-generated', 'lifetime' => 3600, 'request_id' => 'link-request-1'])->assertJsonPath('id', $first->json('id'));
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'another content', 'lifetime' => 3600, 'request_id' => 'link-request-1'])->assertStatus(409);
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'temporary password: synthetic-generated', 'request_id' => 'link-request-1'])->assertStatus(409);
        $this->assertCount(1, $this->conceals());
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'link-request-2', 'unknown' => 1])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/links', ['request_id' => 'link-request-3'])->assertStatus(422);
        // Read by the recipient: the agent no longer gets the link.
        $this->receiptState = 'revealed';
        DB::table('links')->update(['checked_at' => now()->subMinute()]);
        $this->withToken($token)->getJson('/api/v1/links/'.$first->json('id'))->assertJsonPath('state', 'revealed')->assertJsonPath('link', null);
        $this->get($this->base.'/links')->assertSeeText('mailer');
        $this->assertTrue(DB::table('audit')->where('event', 'machine.links_allow')->exists());
    }

    public function test_an_agent_entry_link_waits_for_approval_and_never_returns_the_values(): void
    {
        $this->setUpAccount();
        $entry = $this->entry();
        [$machine, $token] = $this->agent();
        $body = ['entry_id' => $entry, 'text' => 'Your login: {{username}} / {{password}}', 'request_id' => 'entry-link-1'];
        $this->withToken($token)->postJson('/api/v1/links', $body)->assertNotFound();
        $this->grant($machine, $entry, true);
        $options = $this->withToken($token)->getJson('/api/v1/links/options')->assertOk();
        $this->assertSame([['id' => $entry, 'alias' => 'shop-admin', 'fields' => ['username', 'password'], 'approval' => true]], $options->json('entries'));
        $this->withToken($token)->getJson('/api/v1/catalog')->assertJsonPath('entries.0.action', 'onetimesecret.link');
        $this->withToken($token)->postJson('/api/v1/links', ['text' => '{{pin}}'] + $body)->assertStatus(422);
        $pending = $this->withToken($token)->postJson('/api/v1/links', $body)->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('link', null);
        $this->assertSame([], $this->conceals());
        $op = $pending->json('request');
        $this->get($this->base.'/requests')->assertOk()->assertSeeText('onetimesecret.link');
        $this->get($this->base.'/links')->assertSeeText('waiting for approval');
        $this->post($this->base.'/operations/'.$op.'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('Your login: admin / '.$this->sentinel, $this->conceals()[0][2]['secret']['secret']);
        $this->assertSame('succeeded', DB::table('operations')->where('id', $op)->value('state'));
        $status = $this->withToken($token)->getJson('/api/v1/links/'.$pending->json('id'))->assertOk()->assertJsonPath('state', 'active');
        $this->assertStringStartsWith('https://eu.ots.test/secret/', $status->json('link'));
        foreach ([$pending, $status, $options] as $response) {
            $this->assertStringNotContainsString($this->sentinel, $response->getContent());
            $this->assertStringNotContainsString('admin /', $response->getContent());
        }
        // The broker is never told to read the entry for this action, and execute refuses it.
        $this->withToken('')->get('/internal/operations/'.$op, ['Authorization' => 'Bearer test-broker'])->assertExactJson(['allowed' => false]);
        $this->withToken($token)->postJson('/api/v1/execute', ['entry_id' => $entry, 'action' => 'onetimesecret.link', 'request_id' => 'entry-link-2'])->assertStatus(422);
    }

    public function test_a_denied_request_cancels_the_link_and_drops_its_content(): void
    {
        $this->setUpAccount();
        $entry = $this->entry();
        [$machine, $token] = $this->agent();
        $this->grant($machine, $entry, true);
        $pending = $this->withToken($token)->postJson('/api/v1/links', ['entry_id' => $entry, 'request_id' => 'entry-link-1'])->assertJsonPath('state', 'pending');
        $id = $pending->json('id');
        $this->assertArrayHasKey('/v1/secret/data/links/'.$id, $this->backend);
        $this->post($this->base.'/operations/'.$pending->json('request').'/decision', ['decision' => 'deny'])->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/links/'.$id)->assertJsonPath('state', 'cancelled')->assertJsonPath('link', null);
        $this->assertArrayNotHasKey('/v1/secret/data/links/'.$id, $this->backend);
        $this->assertSame([], $this->conceals());
    }

    public function test_an_old_grant_without_approval_still_waits_for_an_owner(): void
    {
        $this->setUpAccount();
        $entry = $this->entry();
        [$machine, $token] = $this->agent();
        $this->grant($machine, $entry, false);
        $pending = $this->withToken($token)->postJson('/api/v1/links', ['entry_id' => $entry, 'text' => 'pw {{password}}', 'request_id' => 'entry-link-1'])
            ->assertJsonPath('state', 'pending')->assertDontSee($this->sentinel);
        $this->assertSame([], $this->conceals());
        // Approved without a person (approved_by) is not approved.
        DB::table('operations')->update(['state' => 'approved']);
        $this->withToken($token)->getJson('/api/v1/links/'.$pending->json('id'))->assertJsonPath('state', 'failed');
        $this->assertSame([], $this->conceals());
        $pending = $this->withToken($token)->postJson('/api/v1/links', ['entry_id' => $entry, 'text' => 'pw {{password}}', 'request_id' => 'entry-link-2'])->assertJsonPath('state', 'pending');
        $this->post($this->base.'/operations/'.$pending->json('request').'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('pw '.$this->sentinel, $this->conceals()[0][2]['secret']['secret']);
        $this->withToken($token)->getJson('/api/v1/links/'.$pending->json('id'))->assertJsonPath('state', 'active');
        // Once the grant is revoked the agent no longer gets the link.
        DB::table('grants')->update(['revoked_at' => now()]);
        $this->withToken($token)->getJson('/api/v1/links/'.$pending->json('id'))->assertJsonPath('link', null);
        DB::table('grants')->update(['revoked_at' => null]);
        $this->withToken($token)->postJson('/api/v1/links/'.$pending->json('id').'/burn')->assertJsonPath('state', 'burned');
    }

    public function test_mcp_tools_create_links(): void
    {
        $this->setUpAccount();
        [, $hidden] = $this->agent(false);
        $list = $this->withToken($hidden)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
        $this->assertNotContains('vault_link_create', array_column($list->json('result.tools'), 'name'));
        [, $token] = $this->agent();
        $list = $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
        $names = array_column($list->json('result.tools'), 'name');
        $this->assertContains('vault_link_create', $names);
        $this->assertContains('vault_link_options', $names);
        $call = $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'vault_link_create', 'arguments' => ['text' => 'synthetic', 'lifetime' => 3600, 'request_id' => 'mcp-link-1']]])->assertOk();
        $this->assertSame('active', json_decode($call->json('result.content.0.text'), true)['state']);
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'vault_link_create', 'arguments' => ['text' => ['x'], 'request_id' => 'mcp-link-2']]])->assertJsonPath('error.code', -32602);
    }

    public function test_grants_and_agents_offer_links_only_when_set_up(): void
    {
        $entry = $this->entry();
        [$machine] = $this->agent(false);
        $this->get($this->base.'/grants')->assertDontSee('value="onetimesecret.link"', false);
        $this->get($this->base.'/agents')->assertDontSeeText('One-time links');
        $this->setUpAccount();
        $this->get($this->base.'/grants')->assertSee('value="onetimesecret.link"', false);
        $this->get($this->base.'/agents')->assertSeeText('One-time links')->assertSee('Allow one-time links for agent mailer', false);
        $grant = ['machine_id' => $machine, 'entry_id' => $entry, 'action' => 'onetimesecret.link', 'days' => 1, 'approval' => '1'];
        $this->post($this->base.'/grants', $grant)->assertSessionHas('form_errors', fn ($e) => str_contains($e['action'][0], 'Allow them on Identities first'));
        DB::table('machines')->update(['links' => true]);
        // Values in a link the agent receives: never without the owner's approval.
        $this->post($this->base.'/grants', array_diff_key($grant, ['approval' => 1]))->assertSessionHas('form_errors', fn ($e) => isset($e['approval']));
        $this->post($this->base.'/grants', $grant)->assertRedirect($this->base.'/grants');
        $this->get('/?workspace='.$this->workspace)->assertSeeText('one-time links with values only after your approval');
        $this->assertTrue(DB::table('grants')->where('action', 'onetimesecret.link')->exists());
    }

    public function test_maintenance_ends_expired_links(): void
    {
        $this->setUpAccount();
        $this->post($this->base.'/links', ['text' => 'synthetic'])->assertOk();
        $id = DB::table('links')->value('id');
        DB::table('links')->update(['expires_at' => now()->subMinute()]);
        Artisan::call('vault:maintenance');
        $this->assertSame('expired', DB::table('links')->value('state'));
        $this->assertArrayNotHasKey('/v1/secret/data/links/'.$id, $this->backend);
    }

    private function pendingLink(): array
    {
        $this->setUpAccount();
        $entry = $this->entry();
        [$machine, $token] = $this->agent();
        $this->grant($machine, $entry, true);
        $pending = $this->withToken($token)->postJson('/api/v1/links', ['entry_id' => $entry, 'text' => 'user {{username}}', 'passphrase' => 'synthetic-phrase', 'request_id' => 'entry-link-1'])
            ->assertJsonPath('state', 'pending');

        return [$machine, $token, $pending->json('id'), $pending->json('request')];
    }

    public function test_stopping_links_for_an_agent_cancels_what_it_asked_for(): void
    {
        [$machine, $token, $id, $op] = $this->pendingLink();
        $this->get($this->base.'/requests')->assertSeeText('fields username · 1 day · passphrase');
        $this->post($this->base.'/machines/'.$machine.'/links', ['links' => '0'])->assertRedirect();
        $this->assertSame('cancelled', DB::table('links')->where('id', $id)->value('state'));
        $this->post($this->base.'/operations/'.$op.'/decision', ['decision' => 'approve'])->assertStatus(409);
        $this->assertSame([], $this->conceals());
        $this->assertArrayNotHasKey('/v1/secret/data/links/'.$id, $this->backend);
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'link-request-9'])->assertForbidden();
    }

    public function test_a_lost_or_changed_draft_is_never_sent(): void
    {
        [, , $id, $op] = $this->pendingLink();
        // The passphrase is gone from the draft: sending it without one would be weaker than what was approved.
        $this->backend['/v1/secret/data/links/'.$id][2] = ['text' => 'user {{username}}', 'passphrase' => null];
        $this->post($this->base.'/operations/'.$op.'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->assertSame([], $this->conceals());
        $this->assertSame('failed', DB::table('links')->where('id', $id)->value('state'));
        $this->assertSame('failed', DB::table('operations')->where('id', $op)->value('state'));
    }

    public function test_a_waiting_request_survives_status_checks_and_cancel_cannot_race_a_send(): void
    {
        [, $token, $id, $op] = $this->pendingLink();
        DB::table('operations')->where('id', $op)->update(['updated_at' => now()->subMinutes(2)]);
        $this->withToken($token)->getJson('/api/v1/links/'.$id)->assertJsonPath('state', 'pending');
        $this->assertSame('pending', DB::table('operations')->where('id', $op)->value('state'));
        // A send has claimed the link: Cancel says so and changes nothing.
        DB::table('links')->where('id', $id)->update(['state' => 'sending']);
        $this->post($this->base.'/links/'.$id.'/burn')->assertSessionHas('alert', fn ($a) => str_contains($a, 'being sent'));
        $this->assertSame('sending', DB::table('links')->where('id', $id)->value('state'));
        $this->assertArrayHasKey('/v1/secret/data/links/'.$id, $this->backend);
    }

    public function test_links_stay_with_the_account_they_were_made_in(): void
    {
        $this->setUpAccount();
        $this->post($this->base.'/links', ['text' => 'synthetic'])->assertOk();
        $id = DB::table('links')->value('id');
        $change = ['region' => 'uk', 'email' => 'links@example.test', 'default_lifetime' => 86400, 'max_lifetime' => 604800];
        $this->get($this->base.'/onetimesecret')->assertSeeText('Change anyway');
        $this->post($this->base.'/onetimesecret', $change)->assertSessionHas('form_errors', fn ($e) => str_contains($e['email'][0], '1 one-time link made in the current account is still open'));
        $this->assertSame('eu', DB::table('link_settings')->value('region'));
        $this->post($this->base.'/onetimesecret', $change + ['orphan' => '1'])->assertRedirect();
        $this->receiptState = 'expired';
        $this->post($this->base.'/links/check')->assertSessionHas('notice', 'No open links of the current account to check. 1 open link of an earlier account cannot be checked here; it ends with its lifetime.');
        $this->assertSame('active', DB::table('links')->value('state'));
        $this->assertArrayHasKey('/v1/secret/data/links/'.$id, $this->backend);
        $this->get($this->base.'/links')->assertSeeText('earlier account')->assertDontSee('aria-label="Burn link Text only"', false);
        $this->post($this->base.'/links/'.$id.'/burn')->assertSessionHas('alert', fn ($a) => str_contains($a, 'earlier Onetime Secret account'));
        $this->assertSame('active', DB::table('links')->value('state'));
    }

    public function test_burn_records_what_the_service_confirms(): void
    {
        $this->setUpAccount();
        $this->post($this->base.'/links', ['text' => 'synthetic'])->assertOk();
        $id = DB::table('links')->value('id');
        $this->burnState = 'revealed';
        $this->post($this->base.'/links/'.$id.'/burn')->assertSessionHas('notice', 'This link had already been opened and read; nothing was burned.');
        $this->assertSame('revealed', DB::table('links')->value('state'));
        // Without its receipt Vault does not claim a burn.
        $this->post($this->base.'/links', ['text' => 'synthetic 2'])->assertOk();
        $second = DB::table('links')->where('id', '!=', $id)->value('id');
        unset($this->backend['/v1/secret/data/links/'.$second]);
        $this->post($this->base.'/links/'.$second.'/burn')->assertSessionHas('alert', fn ($a) => str_contains($a, 'no longer holds'));
        $this->assertSame('active', DB::table('links')->where('id', $second)->value('state'));
    }

    public function test_check_now_says_when_the_service_refused(): void
    {
        $this->setUpAccount();
        $this->post($this->base.'/links', ['text' => 'synthetic'])->assertOk();
        DB::table('link_settings')->update(['email' => 'links@example.test']);
        $this->backend['/v1/secret/data/settings/'.$this->workspace][2] = ['token' => 'wrong-token'];
        $this->post($this->base.'/links/check')->assertSessionHas('notice', fn ($n) => str_starts_with($n, 'Checked 0 of 1 open links.'));
    }

    public function test_a_link_read_before_its_end_is_recorded_as_read(): void
    {
        $this->setUpAccount();
        $this->post($this->base.'/links', ['text' => 'synthetic'])->assertOk();
        DB::table('links')->update(['expires_at' => now()->subMinute()]);
        $this->receiptState = 'revealed';
        Artisan::call('vault:links');
        $this->assertSame('revealed', DB::table('links')->value('state'));
        $this->assertTrue(DB::table('audit')->where('event', 'link.read')->where('actor', 'system')->exists());
    }

    public function test_an_agent_is_capped_and_a_refusal_frees_its_request_id(): void
    {
        $this->setUpAccount();
        [, $token] = $this->agent();
        DB::table('link_settings')->update(['max_lifetime' => 2592000]);
        // Onetime Secret refuses (the fake answers 401 to a wrong token): nothing is kept, the request_id is free.
        $this->backend['/v1/secret/data/settings/'.$this->workspace][2] = ['token' => 'wrong-token'];
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'refused-1'])->assertStatus(503);
        $this->assertSame(0, DB::table('links')->count());
        unset($this->backend['/v1/secret/data/settings/'.$this->workspace][2]);
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'refused-1'])->assertJsonPath('state', 'active');
        for ($i = 2; $i <= 20; $i++) {
            $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'capped-'.$i])->assertOk();
        }
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'capped-21'])->assertStatus(429);
    }

    public function test_a_send_that_crashed_does_not_stay_sending(): void
    {
        [, $token, $id] = $this->pendingLink();
        DB::table('links')->where('id', $id)->update(['state' => 'sending', 'updated_at' => now()->subMinutes(3)]);
        $this->get($this->base.'/links')->assertOk();
        $this->assertSame('failed', DB::table('links')->where('id', $id)->value('state'));
        $this->assertArrayNotHasKey('/v1/secret/data/links/'.$id, $this->backend);
    }

    public function test_a_new_token_replaces_the_old_one_and_the_account_can_be_removed(): void
    {
        $this->setUpAccount();
        $path = '/v1/secret/data/settings/'.$this->workspace;
        $this->post($this->base.'/onetimesecret', ['region' => 'eu', 'email' => 'links@example.test', 'token' => "  synthetic-ots-token\n",
            'default_lifetime' => 86400, 'max_lifetime' => 604800])->assertRedirect();
        $this->assertSame([2 => ['token' => 'synthetic-ots-token']], $this->backend[$path]);
        $this->post($this->base.'/onetimesecret/remove')->assertRedirect()->assertSessionHas('notice', fn ($n) => str_starts_with($n, 'Account removed.'));
        $this->assertArrayNotHasKey($path, $this->backend);
        $this->assertNull(DB::table('link_settings')->first());
        $this->get($this->base.'/links')->assertSeeText('Onetime Secret is not set up.');
    }

    public function test_revoking_an_agent_names_its_open_links(): void
    {
        $this->setUpAccount();
        [$machine, $token] = $this->agent();
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'open-link-1'])->assertOk();
        $this->post($this->base.'/revoke/machines/'.$machine)->assertRedirect()
            ->assertSessionHas('notice', 'Agent mailer revoked. 1 of its one-time links can still be opened: burn them on Links if needed.');
    }

    public function test_a_changed_draft_or_template_is_never_sent(): void
    {
        [, , $id, $op] = $this->pendingLink();
        // Same keys, different text: the seal no longer matches.
        $this->backend['/v1/secret/data/links/'.$id][2] = ['text' => 'user {{username}} pw {{password}}', 'passphrase' => 'synthetic-phrase'];
        $this->post($this->base.'/operations/'.$op.'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->assertSame([], $this->conceals());
        $this->assertSame('failed', DB::table('links')->where('id', $id)->value('state'));
    }

    public function test_a_send_that_crashed_after_keeping_its_receipt_is_followed(): void
    {
        [, , $id, $op] = $this->pendingLink();
        $this->post($this->base.'/operations/'.$op.'/decision', ['decision' => 'approve'])->assertRedirect();
        // As if the process died after the receipt was kept, before the row said "active".
        DB::table('links')->where('id', $id)->update(['state' => 'sending', 'updated_at' => now()->subMinutes(3)]);
        $this->get($this->base.'/links')->assertOk();
        $this->assertSame('active', DB::table('links')->where('id', $id)->value('state'));
        $this->assertArrayHasKey('/v1/secret/data/links/'.$id, $this->backend);
    }

    public function test_an_unanswered_check_after_the_end_keeps_the_receipt(): void
    {
        $this->setUpAccount();
        $this->post($this->base.'/links', ['text' => 'synthetic'])->assertOk();
        $id = DB::table('links')->value('id');
        DB::table('links')->update(['expires_at' => now()->subMinute()]);
        DB::table('link_settings')->update(['email' => 'links@example.test']);
        $this->backend['/v1/secret/data/settings/'.$this->workspace][99] = ['token' => 'wrong-token'];
        Artisan::call('vault:links');
        $this->assertSame('active', DB::table('links')->value('state'));
        $this->assertArrayHasKey('/v1/secret/data/links/'.$id, $this->backend);
        unset($this->backend['/v1/secret/data/settings/'.$this->workspace][99]);
        $this->receiptState = 'burned';
        Artisan::call('vault:links');
        $this->assertSame('burned', DB::table('links')->value('state'));
    }

    public function test_a_retry_matches_the_ask_not_todays_settings(): void
    {
        $this->setUpAccount();
        [, $token] = $this->agent();
        $first = $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'same-ask-1'])->assertOk();
        DB::table('link_settings')->update(['default_lifetime' => 3600, 'max_lifetime' => 3600]);
        $this->withToken($token)->postJson('/api/v1/links', ['text' => 'x', 'request_id' => 'same-ask-1'])->assertJsonPath('id', $first->json('id'));
    }

    public function test_a_persons_link_is_not_kept_and_archiving_names_open_links(): void
    {
        $this->setUpAccount();
        $entry = $this->entry();
        $this->post($this->base.'/links', ['entry_id' => $entry])->assertOk();
        $id = DB::table('links')->value('id');
        $this->assertArrayNotHasKey('url', end($this->backend['/v1/secret/data/links/'.$id]));
        $this->post('/entries/'.$entry.'/archive')->assertRedirect()
            ->assertSessionHas('notice', 'Entry archived. 1 one-time link with its values can still be opened: burn them on Links if needed.');
    }
}
