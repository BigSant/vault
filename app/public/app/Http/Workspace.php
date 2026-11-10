<?php

namespace App\Http;

use App\Models\User;
use App\Vault\Access;
use App\Vault\Catalog;
use App\Vault\Operations;
use App\Vault\Secrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OTPHP\TOTP;

final class Workspace
{
    public function __construct(private Access $access, private Catalog $catalog, private Operations $ops, private Secrets $secrets) {}

    public function index(Request $r)
    {
        $spaces = DB::table('workspaces')->join('members', 'members.workspace_id', '=', 'workspaces.id')->where('members.user_id', $r->user()->id)->select('workspaces.*', 'members.role')->get();
        $id = $r->query('workspace') ?? $spaces->first()?->id;
        $space = $id ? $this->access->workspace($id) : null;
        $projects = $space ? DB::table('projects')->where('workspace_id', $id)->get() : collect();
        $environments = DB::table('environments')->whereIn('project_id', $projects->pluck('id'))->get();
        $query = DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('projects.workspace_id', $id)->where('entries.state', $r->query('state') === 'archived' ? 'archived' : 'active')->select('entries.*', 'environments.name as environment', 'projects.name as project')
            ->selectSub(DB::table('operations')->selectRaw('MAX(updated_at)')->whereColumn('entry_id', 'entries.id')->where('state', 'succeeded'), 'last_used_at');
        if ($r->filled('q')) {
            $term = mb_substr((string) $r->query('q'), 0, 100);
            $query->where(function ($q) use ($term) {
                $q->where('entries.name', 'like', '%'.$term.'%')->orWhere('entries.alias', 'like', '%'.$term.'%')->orWhere('entries.tags', 'like', '%'.$term.'%');
            });
        }
        foreach (['project' => 'projects.id', 'environment' => 'environments.id', 'type' => 'entries.type'] as $filter => $column) {
            if ($r->filled($filter)) {
                $query->where($column, $r->query($filter));
            }
        }

        return view('catalog', ['spaces' => $spaces, 'space' => $space, 'projects' => $projects, 'environments' => $environments, 'entries' => $query->orderBy('entries.name')->paginate(30)->withQueryString()]);
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
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $d = $r->validate(['name' => 'required|string|max:100']);
        DB::transaction(function () use ($workspace, $d) {
            $id = (string) Str::uuid();
            DB::table('projects')->insert(['id' => $id, 'workspace_id' => $workspace, 'name' => $d['name']]);
            foreach (['local', 'test', 'stage', 'prod'] as $name) {
                DB::table('environments')->insert(['id' => (string) Str::uuid(), 'project_id' => $id, 'name' => $name]);
            }$this->access->audit($workspace, 'project.create', $id);
        });

        return redirect('/?workspace='.$workspace);
    }

    public function environment(Request $r, string $workspace)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $this->access->recent($r);
        $d = $r->validate(['project_id' => 'required|uuid', 'name' => 'required|string|max:60']);
        abort_unless(DB::table('projects')->where('id', $d['project_id'])->where('workspace_id', $workspace)->exists(), 404);
        DB::table('environments')->insert(['id' => (string) Str::uuid(), ...$d]);
        $this->access->audit($workspace, 'environment.create', $d['project_id']);

        return redirect('/?workspace='.$workspace);
    }

    public function entryForm(Request $r)
    {
        $workspace = (string) $r->query('workspace');
        $this->access->workspace($workspace, ['owner', 'editor']);
        $entry = $r->query('entry') ? $this->access->entry($r->query('entry'), ['owner', 'editor']) : null;
        abort_if($entry && $entry->workspace_id !== $workspace, 404);
        $environments = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')->where('projects.workspace_id', $workspace)->select('environments.*', 'projects.name as project')->get();

        return view('entry-form', compact('entry', 'environments', 'workspace'));
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

    private function entryView(string $id, ?array $secret = null)
    {
        $entry = $this->access->entry($id);
        $role = $this->access->workspace($entry->workspace_id)->role;
        $grants = collect();
        $events = collect();
        $operations = collect();
        if ($role === 'owner') {
            $operations = DB::table('operations')->where('entry_id', $id)->orderByDesc('created_at')->limit(25)->get();
            $grants = DB::table('grants')->join('machines', 'machines.id', '=', 'grants.machine_id')->where('entry_id', $id)
                ->select('grants.*', 'machines.name as machine', 'machines.revoked_at as machine_revoked_at', 'machines.expires_at as machine_expires_at')->get();
            $events = DB::table('audit')->where('workspace_id', $entry->workspace_id)->where(function ($query) use ($id) {
                $query->where('subject', $id)->orWhereIn('subject', DB::table('operations')->select('id')->where('entry_id', $id))
                    ->orWhereIn('subject', DB::table('grants')->select('id')->where('entry_id', $id));
            })->orderByDesc('id')->limit(30)->get();
        }

        return view('entry', compact('entry', 'role', 'secret', 'grants', 'events', 'operations'));
    }

    public function reveal(Request $r, string $id)
    {
        $this->access->recent($r);
        $d = $r->validate(['version' => 'nullable|integer|min:1']);

        return $this->entryView($id, $this->catalog->reveal($id, (int) ($d['version'] ?? 0)));
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

    public function import(Request $r, string $workspace)
    {
        $this->access->recent($r);
        $d = $r->validate(['import' => 'required|string|max:900000']);
        $this->catalog->import($workspace, $d['import']);

        return redirect('/?workspace='.$workspace);
    }

    public function settings(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        $members = DB::table('members')->join('users', 'users.id', '=', 'members.user_id')->where('workspace_id', $workspace)->select('users.id', 'users.name', 'users.email', 'members.role')->get();
        $machines = DB::table('machines')->where('workspace_id', $workspace)->select('id', 'name', 'expires_at', 'revoked_at')->get();
        $entries = DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')->join('projects', 'projects.id', '=', 'environments.project_id')->where('projects.workspace_id', $workspace)->where('entries.state', 'active')->select('entries.id', 'entries.name', 'entries.type')->get();
        $grants = DB::table('grants')->whereIn('machine_id', $machines->pluck('id'))->get();
        $operations = DB::table('operations')->whereIn('machine_id', $machines->pluck('id'))->orderByDesc('created_at')->limit(50)->get();
        $actions = $this->ops->definitions();

        return view('settings', compact('space', 'members', 'machines', 'entries', 'grants', 'operations', 'actions'));
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
                abort_unless(! empty($d['password']) && $d['role'] !== 'remove', 422);
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

        return redirect('/workspaces/'.$workspace.'/settings');
    }

    public function machine(Request $r, string $workspace)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        $d = $r->validate(['name' => 'required|string|max:100', 'days' => 'required|integer|min:1|max:30']);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $workspace, $d, $token) {
            DB::table('machines')->insert(['id' => $id, 'workspace_id' => $workspace, 'name' => $d['name'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays((int) $d['days']), 'created_at' => now(), 'updated_at' => now()]);
            $this->access->audit($workspace, 'machine.create', $id);
        });

        return view('token', compact('token', 'workspace'));
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
        abort_unless($definition, 422);
        $type = match ($definition['kind']) {
            'browser' => 'login','database' => 'dynamic_database',default => 'api_key'
        };
        abort_unless($entry->type === $type, 422);
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

        return redirect('/workspaces/'.$workspace.'/settings');
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
        return redirect('/workspaces/'.$workspace.'/settings');
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

        return redirect('/workspaces/'.$workspace.'/settings');
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

        return redirect('/workspaces/'.$workspace.'/settings');
    }

    public function audit(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        $events = DB::table('audit')->where('workspace_id', $workspace)->orderByDesc('id')->paginate(50);

        return view('audit',compact('space','events'));
    }
}
