<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

final class StructureTest extends TestCase
{
    private string $connectors;
    private User $owner;

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
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate', ['--force' => true]);
        $this->owner = User::create(['id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        $this->workspace = $this->workspace();
        $this->base = '/workspaces/'.$this->workspace;
        $this->actingAs($this->owner)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time(), 'auth_revision' => $this->owner->credentialRevision()]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connectors) && is_file($this->connectors)) {
            unlink($this->connectors);
        }
        parent::tearDown();
    }

    private function workspace(): string
    {
        $id = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test']);
        DB::table('members')->insert(['workspace_id' => $id, 'user_id' => $this->owner->id, 'role' => 'owner']);

        return $id;
    }

    public function test_entry_and_import_link_to_the_first_missing_setup_step(): void
    {
        $pages = ['/entries/new?workspace='.$this->workspace, $this->base.'/import'];
        foreach ($pages as $page) {
            $this->get($page)->assertOk()->assertSee('href="'.$this->base.'/structure#add-project">Add a project in Setup</a>', false)
                ->assertSee('href="'.$this->base.'/environments#add-environment">Add an environment in Setup</a>', false);
        }
        [, $environment] = $this->project($this->workspace);
        DB::table('environments')->where('id', $environment)->delete();
        foreach ($pages as $page) {
            $this->get($page)->assertOk()->assertDontSee('Add a project in Setup')
                ->assertSee('href="'.$this->base.'/environments#add-environment">Add an environment in Setup</a>', false);
        }
    }

    public function test_management_pages_link_to_their_add_section(): void
    {
        foreach (['agents' => 'agent', 'grants' => 'grant', 'members' => 'member'] as $page => $item) {
            $this->get($this->base.'/'.$page)->assertOk()->assertSeeInOrder([
                'class="btn" href="#add-'.$item.'"', '<h2 id="add-'.$item.'">',
            ], false);
        }
    }

    public function test_foreign_filters_are_ignored_and_never_expose_foreign_grants(): void
    {
        $other = $this->workspace();
        $fixtures = [];
        foreach ([$this->workspace, $other] as $workspace) {
            [$project, $environment] = $this->project($workspace);
            $entry = $this->entry($project, $environment, 'active');
            $machine = (string) Str::uuid();
            DB::table('machines')->insert(['id' => $machine, 'workspace_id' => $workspace, 'name' => $machine, 'token_hash' => hash('sha256', $machine), 'expires_at' => now()->addDay()]);
            DB::table('grants')->insert(['id' => (string) Str::uuid(), 'machine_id' => $machine, 'entry_id' => $entry, 'action' => 'action.'.$machine, 'expires_at' => now()->addDay()]);
            $fixtures[] = [$machine, $entry];
        }
        [[$agent, $entry], [$otherAgent, $otherEntry]] = $fixtures;
        $this->get($this->base.'/grants?agent='.$otherAgent.'&entry='.$otherEntry)
            ->assertOk()->assertViewHas('agent', fn ($value) => $value === null)->assertViewHas('entry', fn ($value) => $value === null)
            ->assertSee('select name="agent"', false)->assertSee('select name="entry"', false)->assertSeeText('Apply')
            ->assertSee('action.'.$agent)->assertDontSee($otherAgent)->assertDontSee($otherEntry);
        $this->get($this->base.'/grants?agent='.$agent.'&entry='.$entry)->assertOk()
            ->assertSee('value="'.$agent.'" selected', false)->assertSee('value="'.$entry.'" selected', false);
    }

    public function test_environment_names_are_unique_whatever_the_case_or_accent(): void
    {
        $this->post($this->base.'/environments', ['name' => 'test'])->assertRedirect($this->base.'/environments');
        $this->post($this->base.'/environments', ['name' => 'Tęst'])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/environments', ['name' => 'qa'])->assertRedirect();
        $qa = DB::table('environments')->where('name', 'qa')->value('id');
        $this->post($this->base.'/environments/'.$qa, ['name' => 'TEST'])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/environments/'.$qa, ['name' => 'QA', 'production' => '1'])->assertRedirect();
        $this->assertDatabaseHas('environments', ['id' => $qa, 'name' => 'QA', 'production' => true]);
    }

    public function test_a_new_workspace_starts_with_the_default_environments_and_projects_have_none(): void
    {
        $this->post('/workspaces', ['name' => 'Fresh'])->assertRedirect();
        $fresh = DB::table('workspaces')->where('name', 'Fresh')->value('id');
        $this->assertEqualsCanonicalizing(['local', 'test', 'stage', 'prod'], DB::table('environments')->where('workspace_id', $fresh)->pluck('name')->all());
        $this->assertTrue((bool) DB::table('environments')->where('workspace_id', $fresh)->where('name', 'prod')->value('production'));
        $this->post('/workspaces/'.$fresh.'/projects', ['name' => 'Example'])->assertRedirect();
        $this->assertSame(4, DB::table('environments')->where('workspace_id', $fresh)->count());
        $this->get('/workspaces/'.$fresh.'/environments')->assertOk()->assertSeeInOrder(['prod', 'local', 'stage', 'test']);
    }

    public static function deletionTargets(): array
    {
        return [['projects', 'project.delete'], ['environments', 'environment.delete']];
    }

    #[DataProvider('deletionTargets')]
    public function test_deletion_cleans_up_destroyed_entries_and_preserves_history(string $target, string $event): void
    {
        [$project, $environment] = $this->project($this->workspace);
        [$otherProject, $otherEnvironment] = $this->project($this->workspace);
        $otherEntry = $this->entry($otherProject, $otherEnvironment, 'destroyed');
        $entry = $this->entry($project, $environment, 'destroyed');
        $machine = (string) Str::uuid();
        $grant = (string) Str::uuid();
        $operation = (string) Str::uuid();
        DB::table('machines')->insert(['id' => $machine, 'workspace_id' => $this->workspace, 'name' => 'Agent', 'token_hash' => hash('sha256', 'fixture'), 'expires_at' => now()->addDay()]);
        DB::table('grants')->insert(['id' => $grant, 'entry_id' => $entry, 'machine_id' => $machine, 'action' => 'test.http', 'expires_at' => now(), 'revoked_at' => now()]);
        DB::table('operations')->insert(['id' => $operation, 'entry_id' => $entry, 'machine_id' => $machine, 'grant_id' => $grant, 'request_id' => 'fixture-request',
            'action' => 'test.http', 'version' => 1, 'state' => 'succeeded', 'expires_at' => now()]);
        DB::table('entry_changes')->insert(['entry_id' => $entry, 'version' => 1, 'event' => 'create', 'changes' => '{}', 'created_at' => now()]);
        DB::table('audit')->insert(['workspace_id' => $this->workspace, 'subject' => $entry, 'actor' => 'user:'.$this->owner->id, 'event' => 'entry.destroy', 'outcome' => 'ok', 'created_at' => now()]);

        $id = $target === 'projects' ? $project : $environment;
        $deleteUrl = $this->base.'/'.$target.'/'.$id.'/delete';
        $this->get($this->base.($target === 'projects' ? '/structure' : '/environments'))->assertOk()->assertSee('action="'.$deleteUrl.'"', false);
        $this->post($deleteUrl)->assertRedirect();
        $this->assertDatabaseMissing($target, ['id' => $id]);
        $this->assertDatabaseMissing('entries', ['id' => $entry]);
        $this->assertDatabaseMissing('grants', ['id' => $grant]);
        $this->assertDatabaseMissing('entry_changes', ['entry_id' => $entry]);
        $this->assertDatabaseHas('operations', ['id' => $operation, 'request_id' => 'fixture-request']);
        $this->assertDatabaseHas('audit', ['workspace_id' => $this->workspace, 'event' => 'entry.destroy', 'subject' => $entry]);
        $this->assertDatabaseHas('audit', ['workspace_id' => $this->workspace, 'event' => $event, 'subject' => $id]);
        $this->assertDatabaseHas('entries', ['environment_id' => $otherEnvironment, 'id' => $otherEntry]);
        $this->assertDatabaseHas('projects', ['id' => $otherProject]);
        // Removing a project leaves the workspace's environments; removing an environment leaves the projects.
        $this->assertDatabaseHas($target === 'projects' ? 'environments' : 'projects', ['id' => $target === 'projects' ? $environment : $project]);
    }

    public static function enrollmentCases(): array
    {
        return [
            'ordinary account' => ['owner@example.test', 16, true],
            'encoded colon in label' => ['team%3adesk@example.test', 16, false],
            'payload exceeds QR capacity' => ['owner@example.test', 5000, false],
        ];
    }

    #[DataProvider('enrollmentCases')]
    public function test_mfa_enrollment_always_offers_the_manual_seed(string $email, int $seedLength, bool $hasQr): void
    {
        $seed = str_repeat('A', $seedLength);
        $this->owner->update(['email' => $email]);
        $this->app['auth']->guard()->logout();
        config(['vault.credentials' => 'data://text/plain,'.rawurlencode('{"role_id":"test","secret_id":"test"}')]);
        Http::preventStrayRequests();
        Http::fake([
            '*/v1/auth/approle/login' => Http::response(['auth' => ['client_token' => 'test']]),
            '*/v1/secret/data/auth/'.$this->owner->id => Http::response(['data' => ['data' => ['totp' => $seed]]]),
        ]);
        $response = $this->withSession(['pending_user' => $this->owner->id, 'pending_at' => time(), 'pending_revision' => $this->owner->credentialRevision()])
            ->get('/mfa')->assertOk()->assertSee($seed)
            ->assertViewHas('qr', fn ($qr) => $hasQr ? str_starts_with($qr ?? '', 'data:image/svg+xml;base64,') : $qr === null);
        if ($hasQr) {
            $response->assertSee('data:image/svg+xml;base64,');
        } else {
            $response->assertDontSee('class="totp-qr"', false);
        }
    }

    public function test_member_of_two_workspaces_cannot_modify_resources_through_the_wrong_workspace(): void
    {
        $other = $this->workspace();
        [$project, $environment] = $this->project($other);
        // Adding an environment names no other workspace's row: only the routes that do are tried here.
        foreach (array_diff_key($this->structureRequests($project, $environment), ['/environments' => true]) as $route => $input) {
            $this->post($this->base.$route, $input)->assertNotFound();
        }
        $this->assertDatabaseHas('projects', ['workspace_id' => $other, 'id' => $project, 'name' => $project]);
        $this->assertDatabaseHas('environments', ['workspace_id' => $other, 'id' => $environment]);
    }

    public function test_viewer_cannot_post_to_project_environment_or_template_routes(): void
    {
        [$project, $environment] = $this->project($this->workspace);
        DB::table('members')->where('workspace_id', $this->workspace)->where('user_id', $this->owner->id)->update(['role' => 'viewer']);
        $requests = $this->structureRequests($project, $environment);
        $requests['/projects'] = ['name' => 'New project'];
        foreach ($requests as $route => $input) {
            $this->post($this->base.$route, $input)->assertNotFound();
        }
        $this->assertDatabaseEmpty('audit');
    }

    private function structureRequests(string $project, string $environment): array
    {
        return [
            '/projects/'.$project => ['name' => 'Renamed'],
            '/environments' => ['name' => 'Added'],
            '/environments/'.$environment => ['name' => 'Renamed'],
            '/environments/'.$environment.'/delete' => [],
            '/projects/'.$project.'/delete' => [],
        ];
    }

    public function test_archived_entries_still_block_deletion_without_purging_destroyed_siblings(): void
    {
        [$project, $environment] = $this->project($this->workspace);
        $archived = $this->entry($project, $environment, 'archived');
        $destroyed = $this->entry($project, $environment, 'destroyed');

        $this->post($this->base.'/projects/'.$project.'/delete')->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/environments/'.$environment.'/delete')->assertStatus(303)->assertSessionHasErrors();
        $this->assertDatabaseHas('entries', ['id' => $archived, 'state' => 'archived']);
        $this->assertDatabaseHas('entries', ['id' => $destroyed, 'state' => 'destroyed']);
        $this->assertDatabaseEmpty('audit');
    }

    public function test_project_and_environment_name_conflicts_return_validation_errors(): void
    {
        [$project, $environment] = $this->project($this->workspace, 'test');
        [$other] = $this->project($this->workspace);
        $this->post($this->base.'/projects', ['name' => $project])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/projects/'.$other, ['name' => $project])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/environments', ['name' => 'test'])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/environments', ['name' => 'qa'])->assertRedirect();
        $this->post($this->base.'/environments/'.$environment, ['name' => 'qa'])->assertStatus(303)->assertSessionHasErrors();
    }

    /** A project and an environment of the workspace (environment names are unique in a workspace). */
    private function project(string $workspace, ?string $environmentName = null): array
    {
        $project = (string) Str::uuid();
        $environment = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $workspace, 'name' => $project]);
        DB::table('environments')->insert(['id' => $environment, 'workspace_id' => $workspace, 'name' => $environmentName ?? 'env-'.substr($environment, 0, 8)]);

        return [$project, $environment];
    }

    private function entry(string $project, string $environment, string $state): string
    {
        $entry = (string) Str::uuid();
        DB::table('entries')->insert(['id' => $entry, 'project_id' => $project, 'environment_id' => $environment, 'name' => 'Example', 'alias' => 'example', 'type' => 'api_key', 'state' => $state, 'version' => 1]);

        return $entry;
    }
}
