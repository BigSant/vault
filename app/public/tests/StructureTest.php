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
    private User $owner;

    private string $workspace;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate', ['--force' => true]);
        $this->owner = User::create(['id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        $this->workspace = $this->workspace();
        $this->base = '/workspaces/'.$this->workspace;
        $this->actingAs($this->owner)->withSession(['mfa_at' => time(), 'login_at' => time(), 'confirmed_at' => time(), 'auth_revision' => $this->owner->credentialRevision()]);
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
            $this->get($page)->assertOk()->assertSee('href="'.$this->base.'/structure#add-project">Add a project in Setup, then an environment</a>', false);
        }
        [$project, $environment] = $this->project($this->workspace);
        DB::table('environments')->where('id', $environment)->delete();
        foreach ($pages as $page) {
            $this->get($page)->assertOk()->assertSee('href="'.$this->base.'/structure#p-'.$project.'">Add an environment to a project in Setup</a>', false);
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
            [, $environment] = $this->project($workspace);
            $entry = $this->entry($environment, 'active');
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

    public function test_template_rejects_names_that_differ_only_in_case_or_accent(): void
    {
        $this->post($this->base.'/template', ['env_name' => ['test', 'Tęst']])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/projects', ['name' => 'Example'])->assertRedirect();
        $project = DB::table('projects')->where('workspace_id', $this->workspace)->value('id');
        $this->assertEqualsCanonicalizing(['local', 'test', 'stage', 'prod'], DB::table('environments')->where('project_id', $project)->pluck('name')->all());
    }

    public function test_project_creation_rolls_back_when_a_stored_template_conflicts_with_the_unique_index(): void
    {
        DB::table('workspaces')->where('id', $this->workspace)->update(['environment_template' => json_encode([
            ['name' => 'qa', 'production' => false], ['name' => 'qa', 'production' => true],
        ])]);

        $this->post($this->base.'/projects', ['name' => 'New project'])->assertStatus(303)->assertSessionHasErrors();
        $this->assertDatabaseMissing('projects', ['workspace_id' => $this->workspace]);
        $this->assertDatabaseMissing('audit', ['workspace_id' => $this->workspace, 'event' => 'project.create']);
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
        $otherEntry = $this->entry($otherEnvironment, 'destroyed');
        $entry = $this->entry($environment, 'destroyed');
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
        $this->get($this->base.'/structure')->assertOk()->assertSee('action="'.$deleteUrl.'"', false);
        $this->post($deleteUrl)->assertRedirect();
        $this->assertDatabaseMissing($target, ['id' => $id]);
        $this->assertDatabaseMissing('environments', ['id' => $environment]);
        $this->assertDatabaseMissing('entries', ['id' => $entry]);
        $this->assertDatabaseMissing('grants', ['id' => $grant]);
        $this->assertDatabaseMissing('entry_changes', ['entry_id' => $entry]);
        $this->assertDatabaseHas('operations', ['id' => $operation, 'request_id' => 'fixture-request']);
        $this->assertDatabaseHas('audit', ['workspace_id' => $this->workspace, 'event' => 'entry.destroy', 'subject' => $entry]);
        $this->assertDatabaseHas('audit', ['workspace_id' => $this->workspace, 'event' => $event, 'subject' => $id]);
        $this->assertDatabaseHas('entries', ['environment_id' => $otherEnvironment, 'id' => $otherEntry]);
        $this->assertDatabaseHas('projects', ['id' => $otherProject]);
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
        foreach ($this->structureRequests($project, $environment) as $route => $input) {
            $this->post($this->base.$route, $input)->assertNotFound();
        }
        $this->assertDatabaseHas('projects', ['workspace_id' => $other, 'id' => $project, 'name' => $project]);
        $this->assertDatabaseHas('environments', ['project_id' => $project, 'id' => $environment, 'name' => 'test']);
    }

    public function test_viewer_cannot_post_to_project_environment_or_template_routes(): void
    {
        [$project, $environment] = $this->project($this->workspace);
        DB::table('members')->where('workspace_id', $this->workspace)->where('user_id', $this->owner->id)->update(['role' => 'viewer']);
        $requests = $this->structureRequests($project, $environment);
        $requests['/projects'] = ['name' => 'New project'];
        $requests['/template'] = ['env_name' => ['qa']];
        foreach ($requests as $route => $input) {
            $this->post($this->base.$route, $input)->assertNotFound();
        }
        $this->assertDatabaseEmpty('audit');
    }

    private function structureRequests(string $project, string $environment): array
    {
        return [
            '/projects/'.$project => ['name' => 'Renamed'],
            '/environments' => ['name' => 'Added', 'project_id' => $project],
            '/environments/'.$environment => ['name' => 'Renamed'],
            '/environments/'.$environment.'/delete' => [],
            '/projects/'.$project.'/delete' => [],
        ];
    }

    public function test_archived_entries_still_block_deletion_without_purging_destroyed_siblings(): void
    {
        [$project, $environment] = $this->project($this->workspace);
        $archived = $this->entry($environment, 'archived');
        $destroyed = $this->entry($environment, 'destroyed');

        $this->post($this->base.'/projects/'.$project.'/delete')->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/environments/'.$environment.'/delete')->assertStatus(303)->assertSessionHasErrors();
        $this->assertDatabaseHas('entries', ['id' => $archived, 'state' => 'archived']);
        $this->assertDatabaseHas('entries', ['id' => $destroyed, 'state' => 'destroyed']);
        $this->assertDatabaseEmpty('audit');
    }

    public function test_project_and_environment_name_conflicts_return_validation_errors(): void
    {
        [$project, $environment] = $this->project($this->workspace);
        [$other] = $this->project($this->workspace);
        $this->post($this->base.'/projects', ['name' => $project])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/projects/'.$other, ['name' => $project])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/environments', ['project_id' => $project, 'name' => 'test'])->assertStatus(303)->assertSessionHasErrors();
        $this->post($this->base.'/environments', ['project_id' => $project, 'name' => 'qa'])->assertRedirect();
        $this->post($this->base.'/environments/'.$environment, ['name' => 'qa'])->assertStatus(303)->assertSessionHasErrors();
    }

    private function project(string $workspace): array
    {
        $project = (string) Str::uuid();
        $environment = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $project, 'workspace_id' => $workspace, 'name' => $project]);
        DB::table('environments')->insert(['id' => $environment, 'project_id' => $project, 'name' => 'test']);

        return [$project, $environment];
    }

    private function entry(string $environment, string $state): string
    {
        $entry = (string) Str::uuid();
        DB::table('entries')->insert(['id' => $entry, 'environment_id' => $environment, 'name' => 'Example', 'alias' => 'example', 'type' => 'api_key', 'state' => $state, 'version' => 1]);

        return $entry;
    }
}
