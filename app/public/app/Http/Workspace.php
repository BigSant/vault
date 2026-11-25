<?php

namespace App\Http;

use App\Models\User;
use App\Vault\Access;
use App\Vault\Catalog;
use App\Vault\EntryTypes;
use App\Vault\Operations;
use App\Vault\Secrets;
use App\Vault\Structure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OTPHP\TOTP;

final class Workspace
{
    public function __construct(private Access $access, private Catalog $catalog, private Operations $ops, private Secrets $secrets,
        private EntryTypes $types, private Structure $structure) {}

    public function index(Request $r)
    {
        $spaces = DB::table('workspaces')->join('members', 'members.workspace_id', '=', 'workspaces.id')->where('members.user_id', $r->user()->id)->select('workspaces.*', 'members.role')->orderBy('workspaces.name')->get();
        $id = $r->query('workspace') ?? $spaces->firstWhere('id', $r->session()->get('last_workspace'))?->id ?? $spaces->first()?->id;
        $space = $id ? $this->access->workspace($id) : null;
        if ($r->query('due') === '1') {
            $r->query->set('state', 'due');
            $r->query->remove('due');
        }
        $projects = $space ? DB::table('projects')->where('workspace_id', $id)->orderBy('name')->get() : collect();
        $environments = $space ? $this->workspaceEnvironments($id) : collect();
        $query = DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('projects.workspace_id', $id)->select('entries.*', 'environments.name as environment', 'projects.name as project')
            ->selectSub(DB::table('operations')->selectRaw('MAX(updated_at)')->whereColumn('entry_id', 'entries.id')->where('state', 'succeeded'), 'last_used_at');
        $archivedCount = (clone $query)->where('entries.state', 'archived')->count();
        $query->where('entries.state', $r->query('state') === 'archived' ? 'archived' : 'active');
        $scopeCount = (clone $query)->count();
        if ($r->query('state') === 'due') {
            $query->where('entries.rotate_at', '<', now());
        }
        foreach (['project' => 'projects.id', 'environment' => 'environments.id', 'type' => 'entries.type'] as $filter => $column) {
            if ($r->filled($filter)) {
                $query->where($column, $r->query($filter));
            }
        }

        if ($r->filled('q')) {
            $term = mb_substr((string) $r->query('q'), 0, 100);
            $query->where(function ($q) use ($term) {
                $q->where('entries.name', 'like', '%'.$term.'%')->orWhere('entries.alias', 'like', '%'.$term.'%')->orWhere('entries.tags', 'like', '%'.$term.'%');
            });
        }

        $query->addSelect('environments.production');
        $active = DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('projects.workspace_id', $id)->where('entries.state', 'active');
        $stats = $space ? [
            'active' => $active->count(),
            'due' => (clone $active)->whereNotNull('entries.rotate_at')->where('entries.rotate_at', '<', now())->count(),
            'production' => (clone $active)->where('environments.production', true)->count(),
            'used' => (clone $active)->whereExists(fn ($q) => $q->from('operations')->whereColumn('operations.entry_id', 'entries.id')->where('operations.state', 'succeeded')->where('operations.updated_at', '>=', now()->subDays(7)))->count(),
        ] : ['active' => 0, 'due' => 0, 'production' => 0, 'used' => 0];

        return view('catalog', ['spaces' => $spaces, 'space' => $space, 'projects' => $projects, 'environments' => $environments,
            'typeNames' => $space ? $this->types->names($space->id) : [], 'stats' => $stats, 'archivedCount' => $archivedCount, 'scopeCount' => $scopeCount, 'entries' => $query->orderBy('entries.name')->paginate(30)->withQueryString()]);
    }

    public function workspace(Request $r)
    {
        $this->access->recent($r);
        $d = $r->validate(['name' => 'required|string|max:100']);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($r, $d, $id) {
            DB::table('workspaces')->insert(['id' => $id, 'name' => $d['name'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('members')->insert(['workspace_id' => $id, 'user_id' => $r->user()->id, 'role' => 'owner']);
            $this->access->audit($id, 'workspace.create', $id);
        });

        return redirect('/?workspace='.$id);
    }

    public function project(Request $r, string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $this->structure->createProject($space, (string) $r->input('name'));

        return redirect($r->input('back') === 'structure' ? '/workspaces/'.$workspace.'/structure' : '/?workspace='.$workspace);
    }

    public function environment(Request $r, string $workspace)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $d = $r->validate(['project_id' => 'required|uuid']);
        $this->structure->addEnvironment($workspace, $d['project_id'], (string) $r->input('name'), $r->input('production') === '1');

        return redirect($r->input('back') === 'structure' ? '/workspaces/'.$workspace.'/structure' : '/?workspace='.$workspace);
    }

    public function structurePage(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner', 'editor']);
        $projects = DB::table('projects')->where('workspace_id', $workspace)->orderBy('name')->get();
        $environments = $this->workspaceEnvironments($workspace)->groupBy('project_id');
        $counts = DB::table('entries')->whereIn('environment_id', $environments->flatten()->pluck('id'))->where('state', '!=', 'destroyed')->groupBy('environment_id')
            ->selectRaw('environment_id, COUNT(*) as total')->pluck('total', 'environment_id');
        $template = $this->structure->template($space);

        return view('structure', compact('space', 'projects', 'environments', 'counts', 'template'));
    }

    public function template(Request $r, string $workspace)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $this->structure->saveTemplate($workspace, (array) $r->input('env_name', []), (array) $r->input('env_production', []));

        return redirect('/workspaces/'.$workspace.'/structure');
    }

    public function renameProject(Request $r, string $workspace, string $project)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $this->structure->renameProject($workspace, $project, (string) $r->input('name'));

        return redirect('/workspaces/'.$workspace.'/structure');
    }

    public function deleteProject(Request $r, string $workspace, string $project)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $this->structure->deleteProject($workspace, $project);

        return redirect('/workspaces/'.$workspace.'/structure');
    }

    public function updateEnvironment(Request $r, string $workspace, string $environment)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $this->structure->updateEnvironment($workspace, $environment, (string) $r->input('name'), $r->input('production') === '1');

        return redirect('/workspaces/'.$workspace.'/structure');
    }

    public function deleteEnvironment(Request $r, string $workspace, string $environment)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $this->structure->deleteEnvironment($workspace, $environment);

        return redirect('/workspaces/'.$workspace.'/structure');
    }

    public function typesPage(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner', 'editor']);
        $types = $this->types->all($workspace);
        $usage = $this->types->usage($workspace);
        $kinds = EntryTypes::KINDS;

        return view('types', compact('space', 'types', 'usage', 'kinds'));
    }

    public function saveType(Request $r, string $workspace, ?string $key = null)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $key = $this->types->save($workspace, $key, $r->all());

        return redirect('/workspaces/'.$workspace.'/types#type-'.$key);
    }

    public function deleteType(Request $r, string $workspace, string $key)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $this->types->delete($workspace, $key);

        return redirect('/workspaces/'.$workspace.'/types');
    }

    public function entryForm(Request $r)
    {
        $workspace = (string) $r->query('workspace');
        $space = $this->access->workspace($workspace, ['owner', 'editor']);
        $entry = $r->query('entry') ? $this->access->entry($r->query('entry'), ['owner', 'editor']) : null;
        abort_if($entry && $entry->workspace_id !== $workspace, 404);
        $environments = $this->workspaceEnvironments($workspace);
        $types = $this->types->all($workspace)->when($entry, fn ($all) => $all->where('key', $entry->type))->values();
        abort_if($types->isEmpty() && $entry, 409);

        return view('entry-form', compact('entry', 'environments', 'workspace', 'types', 'space'));
    }

    public function create(Request $r)
    {
        $this->access->recent($r);
        $id = $this->catalog->create($r->all());

        return redirect('/entries/'.$id);
    }

    public function update(Request $r, string $id)
    {
        $this->access->recent($r);
        $this->catalog->update($id, $r->all());

        return redirect('/entries/'.$id);
    }

    public function detail(string $id)
    {
        return $this->entryView($id);
    }

    private function entryView(string $id, ?array $secret = null, ?int $shownVersion = null, array $differs = [])
    {
        $entry = $this->access->entry($id);
        $space = $this->access->workspace($entry->workspace_id);
        $role = $space->role;
        $type = $this->types->find($entry->workspace_id, $entry->type);
        $labels = $type ? array_column($type->fields, 'label', 'key') : [];
        $environment = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')->where('environments.id', $entry->environment_id)
            ->select('environments.name', 'environments.production', 'projects.name as project')->first();
        $historyOlder = false;
        $history = $entry->state === 'destroyed' ? [] : $this->catalog->history($entry, older: $historyOlder);
        $grants = collect();
        $events = collect();
        $operations = collect();
        $waitingCount = 0;
        if ($role === 'owner') {
            $query = $this->requestRows($space->id)->where('operations.entry_id', $id);
            [$waiting, $finished] = $this->requestSections($query, 25);
            $waitingCount = $waiting->count();
            $operations = $waiting->concat($finished);
            $grants = DB::table('grants')->join('machines', 'machines.id', '=', 'grants.machine_id')->where('entry_id', $id)
                ->select('grants.*', 'machines.name as machine', 'machines.revoked_at as machine_revoked_at', 'machines.expires_at as machine_expires_at')->get()->each(fn ($grant) => $this->grantStatus($grant));
            $events = DB::table('audit')->where('workspace_id', $entry->workspace_id)->where(function ($query) use ($id) {
                $query->where('subject', $id)->orWhereIn('subject', DB::table('operations')->select('id')->where('entry_id', $id))
                    ->orWhereIn('subject', DB::table('grants')->select('id')->where('entry_id', $id));
            })->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get();
            $this->resolveEvents($space->id, $events);
        }

        return view('entry', compact('entry', 'role', 'secret', 'grants', 'events', 'operations', 'waitingCount', 'type', 'labels', 'environment', 'history', 'historyOlder', 'shownVersion', 'differs', 'space'));
    }

    public function reveal(Request $r, string $id)
    {
        $this->access->recent($r);
        $d = $r->validate(['version' => 'nullable|integer|min:1']);
        // The version label and comparison use the version the backend read actually returned, not a later SQL read.
        $shown = $this->catalog->revealVersion($id, (int) ($d['version'] ?? 0));
        $differs = $this->catalog->differsFromCurrent($id, $shown['payload'], $shown['version']);

        return $this->entryView($id, $shown['payload'], $shown['version'], $differs);
    }

    public function archive(Request $r, string $id)
    {
        $this->access->recent($r);
        $entry = $this->access->entry($id);
        $this->catalog->archive($id);

        return redirect('/?workspace='.$entry->workspace_id);
    }

    public function restoreVersion(Request $r, string $id)
    {
        $this->access->recent($r);
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $this->catalog->restoreVersion($id, (int) $d['version']);

        return redirect('/entries/'.$id);
    }

    public function unarchive(Request $r, string $id)
    {
        $this->access->recent($r);
        $this->catalog->unarchive($id);

        return redirect('/entries/'.$id);
    }

    public function destroy(Request $r, string $id)
    {
        $this->access->recent($r);
        $d = $r->validate(['confirmation' => 'required|in:DELETE']);
        $entry = $this->access->entry($id);
        $this->catalog->destroy($id);

        return redirect('/?workspace='.$entry->workspace_id);
    }

    private function workspaceEnvironments(string $workspace)
    {
        return DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')->where('projects.workspace_id', $workspace)
            ->select('environments.*', 'projects.name as project')->orderBy('projects.name')->orderByDesc('environments.production')->orderBy('environments.name')->get();
    }

    public function importForm(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner', 'editor']);

        $environments = $this->workspaceEnvironments($workspace);
        $types = $this->types->all($workspace);

        return view('import', compact('space', 'environments', 'types'));
    }

    public function import(Request $r, string $workspace)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $d = $r->validate(['import' => 'required|string|max:900000']);
        $this->catalog->import($workspace, $d['import']);

        return redirect('/?workspace='.$workspace);
    }

    public function settings(string $workspace)
    {
        $this->access->workspace($workspace, ['owner']);

        return redirect('/workspaces/'.$workspace.'/requests');
    }

    private function requestRows(string $workspace)
    {
        return DB::table('operations')->join('machines', 'machines.id', '=', 'operations.machine_id')
            ->join('entries', 'entries.id', '=', 'operations.entry_id')
            ->join('environments', 'environments.id', '=', 'entries.environment_id')
            ->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('machines.workspace_id', $workspace)->where('projects.workspace_id', $workspace)
            ->select('operations.*', 'machines.name as agent', 'entries.name as entry', 'environments.name as environment', 'environments.production', 'projects.name as project')
            ->orderByDesc('operations.created_at')->orderByDesc('operations.id');
    }

    private function requestSections(Builder $operations, int $historyLimit): array
    {
        $states = ['pending', 'requires_user_action'];
        $now = now();
        $waiting = (clone $operations)->whereIn('operations.state', $states)->where('operations.expires_at', '>', $now)->get();
        $history = $operations->where(fn ($query) => $query->whereNotIn('operations.state', $states)->orWhere('operations.expires_at', '<=', $now))->limit($historyLimit)->get();

        $revoked = $history->where('state', 'revoked')->pluck('id');
        $denied = $revoked->isEmpty() ? [] : DB::table('audit')->where('event', 'operation.deny')
            ->whereIn('subject', $revoked)->pluck('subject')->flip()->all();
        foreach ($waiting->concat($history) as $row) {
            $row->decision_label = match (true) {
                isset($denied[$row->id]) => 'denied',
                in_array($row->state, $states, true) && $row->expires_at <= $now->toDateTimeString() => 'expired',
                default => $row->state,
            };
        }

        return [$waiting, $history];
    }

    public function requests(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        [$waiting, $history] = $this->requestSections($this->requestRows($workspace), 50);

        return view('requests', compact('space', 'waiting', 'history'));
    }

    public function agents(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        $machines = DB::table('machines')->where('workspace_id', $workspace)->select('id', 'name', 'expires_at', 'revoked_at')
            ->selectSub(DB::table('grants')->selectRaw('COUNT(*)')->whereColumn('machine_id', 'machines.id')
                ->whereNull('grants.revoked_at')->where('grants.expires_at', '>', now())
                ->whereNull('machines.revoked_at')->where('machines.expires_at', '>', now()), 'active_grants')
            ->orderBy('name')->get();

        return view('agents', compact('space', 'machines'));
    }

    private function grantStatus(object $grant): void
    {
        $grant->effective_expires_at = min($grant->expires_at, $grant->machine_expires_at);
        $grant->state = strtotime($grant->effective_expires_at) <= time() ? 'expired' : 'active';
        $grant->expiry_reason = null;
        $grant->effective_revoked_at = $grant->revoked_at;
        if ($grant->revoked_at || $grant->machine_revoked_at) {
            $grant->state = 'revoked';
            if ($grant->machine_revoked_at && (! $grant->revoked_at || $grant->machine_revoked_at <= $grant->revoked_at)) {
                $grant->effective_revoked_at = $grant->machine_revoked_at;
                $grant->expiry_reason = 'agent revoked';
            }
        } elseif ($grant->machine_expires_at < $grant->expires_at) {
            $grant->expiry_reason = $grant->state === 'expired' ? 'agent expired' : 'agent expires';
        }
    }

    public function grants(Request $r, string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        $machines = DB::table('machines')->where('workspace_id', $workspace)->select('id', 'name', 'expires_at', 'revoked_at')->orderBy('name')->get()->keyBy('id');
        $entries = DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('projects.workspace_id', $workspace)->select('entries.id', 'entries.name', 'entries.type', 'entries.state', 'environments.production', 'projects.name as project', 'environments.name as environment')->orderBy('entries.name')->get()->keyBy('id');
        $agent = is_string($r->query('agent')) ? $machines->get($r->query('agent')) : null;
        $entry = is_string($r->query('entry')) ? $entries->get($r->query('entry')) : null;
        $query = DB::table('grants')->whereIn('machine_id', $machines->keys())->whereIn('entry_id', $entries->keys());
        $total = ($agent || $entry) ? (clone $query)->count() : null;
        $grants = $query
            ->when($agent, fn ($q) => $q->where('machine_id', $agent->id))->when($entry, fn ($q) => $q->where('entry_id', $entry->id))
            ->join('machines', 'machines.id', '=', 'grants.machine_id')->select('grants.*', 'machines.revoked_at as machine_revoked_at', 'machines.expires_at as machine_expires_at')
            ->orderByRaw('CASE WHEN grants.revoked_at IS NULL AND machines.revoked_at IS NULL AND grants.expires_at > ? AND machines.expires_at > ? THEN 0 ELSE 1 END', [now(), now()])
            ->orderBy('machines.name')->orderBy('grants.action')->orderBy('grants.id')->get()->each(fn ($grant) => $this->grantStatus($grant));
        $actions = $this->ops->definitions();
        $types = $this->types->all($workspace);
        $typeNames = $types->pluck('name', 'key');
        $typeFields = $types->mapWithKeys(fn ($type) => [$type->key => array_column(array_filter($type->fields, fn ($field) => $field['required']), 'key')]);

        return view('grants', compact('space', 'machines', 'entries', 'agent', 'entry', 'grants', 'actions', 'typeNames', 'typeFields', 'total'));
    }

    public function members(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        $members = DB::table('members')->join('users', 'users.id', '=', 'members.user_id')->where('workspace_id', $workspace)
            ->select('users.id', 'users.name', 'users.email', 'members.role')->orderBy('users.name')->get();

        return view('members', compact('space', 'members'));
    }

    public function member(Request $r, string $workspace)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        $d = $r->validate(['email' => 'required|email|max:200', 'name' => 'required|string|max:100', 'password' => 'nullable|string|min:14|max:200', 'role' => 'required|in:owner,editor,viewer,remove']);
        DB::transaction(function () use ($workspace, $d) {
            DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            $user = User::where('email', strtolower($d['email']))->first();
            if (! $user) {
                if ($d['role'] === 'remove') {
                    throw ValidationException::withMessages(['email' => 'Choose an existing member to remove.']);
                }
                if (empty($d['password'])) {
                    throw ValidationException::withMessages(['password' => 'Enter a first password for a new user.']);
                }
                $id = (string) Str::uuid();
                $this->secrets->write('auth/'.$id, ['totp' => TOTP::generate()->getSecret()], 0);
                $user = User::create(['id' => $id, 'name' => $d['name'], 'email' => strtolower($d['email']), 'password' => Hash::make($d['password'])]);
            }
            $member = DB::table('members')->where('workspace_id', $workspace)->where('user_id', $user->id)->first();
            if ($member?->role === 'owner' && $d['role'] !== 'owner') {
                abort_if(DB::table('members')->where('workspace_id', $workspace)->where('role', 'owner')->count() <= 1, 409);
            }
            if ($d['role'] === 'remove') {
                DB::table('members')->where('workspace_id', $workspace)->where('user_id', $user->id)->delete();
            } else {
                DB::table('members')->updateOrInsert(['workspace_id' => $workspace, 'user_id' => $user->id], ['role' => $d['role']]);
            }
            $this->access->audit($workspace, 'member.change', $user->id);
        });

        return redirect('/workspaces/'.$workspace.'/members');
    }

    public function machine(Request $r, string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        $d = $r->validate(['name' => 'required|string|max:100', 'days' => 'required|integer|min:1|max:30']);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        $expiresAt = now()->addDays((int) $d['days']);
        DB::transaction(function () use ($id, $workspace, $d, $token, $expiresAt) {
            DB::table('machines')->insert(['id' => $id, 'workspace_id' => $workspace, 'name' => $d['name'], 'token_hash' => hash('sha256', $token), 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now()]);
            $this->access->audit($workspace, 'machine.create', $id);
        });

        return view('token', ['token' => $token, 'id' => $id, 'space' => $space, 'name' => $d['name'], 'expiresAt' => $expiresAt]);
    }

    public function grant(Request $r, string $workspace)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        $d = $r->validate(['machine_id' => 'required|uuid', 'entry_id' => 'required|uuid', 'action' => 'required|string|max:100', 'days' => 'required|integer|min:1|max:30', 'approval' => 'nullable|in:1']);
        $entry = $this->access->entry($d['entry_id'], ['owner']);
        abort_unless($entry->workspace_id === $workspace, 404);
        abort_unless(DB::table('machines')->where('id', $d['machine_id'])->where('workspace_id', $workspace)->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 404);
        $definition = $this->ops->definitions()[$d['action']] ?? null;
        if (! $definition) {
            throw ValidationException::withMessages(['action' => 'Choose an available action.']);
        }
        // The action reads these fields from the secret, so the entry's type must require them.
        $type = $this->types->find($workspace, $entry->type);
        $required = $type ? array_column(array_filter($type->fields, fn ($f) => $f['required']), 'key') : [];
        if (array_diff(EntryTypes::actionFields($definition['kind']), $required)) {
            throw ValidationException::withMessages(['action' => 'This action needs the entry type to have these required fields: '.implode(', ', EntryTypes::actionFields($definition['kind'])).'.']);
        }
        // The type only describes new versions; the version the action would read must actually hold the fields.
        if ($missing = $this->catalog->missingFields($entry->id, EntryTypes::actionFields($definition['kind']))) {
            throw ValidationException::withMessages(['action' => 'The current version of this entry has no value for: '.implode(', ', $missing).'. Edit the entry first.']);
        }
        DB::transaction(function () use ($workspace, $d) {
            DB::table('machines')->where('id', $d['machine_id'])->lockForUpdate()->first();
            $existing = DB::table('grants')->where('machine_id', $d['machine_id'])->where('entry_id', $d['entry_id'])->where('action', $d['action'])->first();
            if ($existing) {
                DB::table('operations')->where('grant_id', $existing->id)->whereIn('state', ['pending', 'approved', 'running', 'requires_user_action'])->update(['state' => 'revoked', 'updated_at' => now()]);
            }
            DB::table('grants')->updateOrInsert(['machine_id' => $d['machine_id'], 'entry_id' => $d['entry_id'], 'action' => $d['action']],
                ['id' => $existing?->id ?? (string) Str::uuid(), 'approval' => isset($d['approval']), 'expires_at' => now()->addDays((int) $d['days']), 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            $this->access->audit($workspace, 'grant.create', $d['entry_id']);
        });

        return redirect('/workspaces/'.$workspace.'/grants');
    }

    public function revoke(Request $r, string $workspace, string $kind, string $id)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        abort_unless(in_array($kind, ['machines', 'grants']), 404);
        $row = DB::table($kind)->where('id', $id)->first();
        abort_unless($row, 404);
        $machine = $kind === 'machines' ? $row : DB::table('machines')->where('id', $row->machine_id)->first();
        abort_unless($machine?->workspace_id === $workspace, 404);
        DB::transaction(function () use ($workspace, $kind, $id, $machine) {
            DB::table('machines')->where('id', $machine->id)->lockForUpdate()->first();
            DB::table('operations')->where($kind === 'machines' ? 'machine_id' : 'grant_id', $id)->whereIn('state', ['pending', 'approved', 'running', 'requires_user_action'])->update(['state' => 'revoked', 'updated_at' => now()]);
            DB::table($kind)->where('id', $id)->update(['revoked_at' => now()]);
            $this->access->audit($workspace, $kind.'.revoke', $id);
        });

        // The broker rechecks authorization before network requests and every second while waiting.
        return redirect('/workspaces/'.$workspace.($kind === 'machines' ? '/agents' : '/grants'));
    }

    public function decision(Request $r, string $workspace, string $id)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        $d = $r->validate(['decision' => 'required|in:approve,deny']);
        DB::transaction(function () use ($r, $workspace, $id, $d) {
            $op = DB::table('operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($op, 404);
            abort_unless(DB::table('machines')->where('id', $op->machine_id)->where('workspace_id', $workspace)->exists(), 404);
            abort_unless($op->state === 'pending' && strtotime($op->expires_at) > time(), 409);
            DB::table('operations')->where('id', $id)->update(['state' => $d['decision'] === 'approve' ? 'approved' : 'revoked', 'approved_by' => $r->user()->id, 'updated_at' => now()]);
            if ($d['decision'] === 'approve') {
                abort_unless($this->ops->authorized($id), 409);
            }
            $this->access->audit($workspace, 'operation.'.$d['decision'], $id);
        });

        return $this->afterDecision($r, $workspace);
    }

    public function mfa(Request $r, string $workspace, string $id)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        $d = $r->validate(['code' => 'required|regex:/^\d{6,8}$/D']);
        $op = $this->ops->authorized($id);
        abort_unless($op && $op->state === 'requires_user_action', 409);
        abort_unless(DB::table('machines')->where('id', $op->machine_id)->where('workspace_id', $workspace)->exists(), 404);
        $this->access->audit($workspace, 'operation.mfa', $id);
        $this->ops->broker('POST', '/operations/'.$id.'/mfa', $d);

        return $this->afterDecision($r, $workspace);
    }

    private function afterDecision(Request $r, string $workspace)
    {
        $back = $r->input('back');
        if (is_string($back) && str_starts_with($back, '/entries/')) {
            $id = substr($back, strlen('/entries/'));
            if (Str::isUuid($id) && DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')
                ->join('projects', 'projects.id', '=', 'environments.project_id')->where('projects.workspace_id', $workspace)
                ->where('entries.id', $id)->exists()) {
                return redirect('/entries/'.$id);
            }
        }

        return redirect('/workspaces/'.$workspace.'/requests');
    }

    private function actorNames(string $workspace, $actors): array
    {
        $names = [];
        foreach (['user' => DB::table('users'), 'machine' => DB::table('machines')->where('workspace_id', $workspace)] as $kind => $query) {
            $ids = $actors->filter(fn ($actor) => str_starts_with($actor, $kind.':'))->map(fn ($actor) => Str::after($actor, ':'));
            foreach ($query->whereIn('id', $ids)->pluck('name', 'id') as $id => $name) {
                $names[$kind.':'.$id] = $name;
            }
        }

        return $names;
    }

    private function resolveEvents(string $workspace, $events, ?array $names = null): void
    {
        $names ??= $this->actorNames($workspace, $events->pluck('actor')->unique());
        $ids = $events->pluck('subject')->filter()->unique();
        $entries = DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')
            ->join('projects', 'projects.id', '=', 'environments.project_id')->where('projects.workspace_id', $workspace);
        $related = [];
        foreach (['operations', 'grants'] as $table) {
            $related += (clone $entries)->join($table, $table.'.entry_id', '=', 'entries.id')
                ->whereIn($table.'.id', $ids)->select('entries.id as entry_id', $table.'.id as subject_id')->get()->pluck('entry_id', 'subject_id')->all();
        }
        $entryNames = $entries->whereIn('entries.id', $ids->merge(array_values($related)))->pluck('entries.name', 'entries.id');
        $agents = DB::table('machines')->where('workspace_id', $workspace)->whereIn('id', $ids)->pluck('name', 'id');
        $subjects = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')
            ->union(DB::table('workspaces')->where('id', $workspace)->whereIn('id', $ids)->pluck('name', 'id'))
            ->union(DB::table('projects')->where('workspace_id', $workspace)->whereIn('id', $ids)->pluck('name', 'id'))
            ->union(DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')
                ->where('projects.workspace_id', $workspace)->whereIn('environments.id', $ids)->pluck('environments.name', 'environments.id'));
        $types = DB::table('entry_types')->where('workspace_id', $workspace)
            ->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('key', $ids))->get(['id', 'key', 'name']);
        $typeNames = $types->pluck('name', 'id')->union($types->pluck('name', 'key'));
        foreach ($events as $event) {
            $event->actor_kind = match (Str::before($event->actor, ':')) {
                'machine' => 'agent', 'user' => 'user', default => 'system',
            };
            $event->actor_label = $names[$event->actor] ?? $event->actor_kind;
            $entry = $related[$event->subject] ?? $event->subject;
            $event->subject_label = $entryNames->get($entry) ?? $agents->get($event->subject)
                ?? $subjects->get($event->subject) ?? (str_starts_with($event->event, 'type.') ? $typeNames->get($event->subject) : null) ?? 'removed item';
            $event->subject_url = $entryNames->has($entry) ? '/entries/'.$entry
                : ($agents->has($event->subject) ? '/workspaces/'.$workspace.'/agents#agent-'.$event->subject : null);
        }
    }

    public function audit(Request $r, string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        $query = DB::table('audit')->where('workspace_id', $workspace);
        $actors = (clone $query)->distinct()->pluck('actor');
        $names = $this->actorNames($workspace, $actors);
        $actorOptions = $actors->mapWithKeys(fn ($actor) => [$actor => $names[$actor] ?? 'system'])->sort();
        $groups = ['entry' => ['entry'], 'grant' => ['grant', 'grants'], 'agent' => ['machine', 'machines'],
            'request' => ['operation'], 'member' => ['member', 'owner'], 'session' => ['session'], 'setup' => ['workspace', 'project', 'environment', 'type']];
        $filters = $r->validate(['group' => 'nullable|string|in:'.implode(',', array_keys($groups)), 'actor' => 'nullable|string|max:150',
            'outcome' => 'nullable|in:ok,denied,failed', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $total = array_filter($filters) ? (clone $query)->count() : null;
        if ($group = $filters['group'] ?? null) {
            $query->where(function ($q) use ($groups, $group) {
                foreach ($groups[$group] as $prefix) {
                    $q->orWhere('event', 'like', $prefix.'.%');
                }
            });
        }
        if ($actor = $filters['actor'] ?? null) {
            $query->where('actor', $actor);
        }
        if ($outcome = $filters['outcome'] ?? null) {
            $query->where('outcome', $outcome);
        }
        if ($from = $filters['from'] ?? null) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $filters['to'] ?? null) {
            $query->whereDate('created_at', '<=', $to);
        }
        $events = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(50)->withQueryString();
        $this->resolveEvents($workspace, $events->getCollection(), $names);

        return view('audit', compact('space', 'events', 'groups', 'actorOptions', 'total'));
    }
}
