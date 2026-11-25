<?php

namespace App\Vault;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class Access
{
    public function workspace(string $id, array $roles = ['owner', 'editor', 'viewer']): object
    {
        $row = DB::table('members')->join('workspaces', 'workspaces.id', '=', 'members.workspace_id')
            ->where('workspace_id', $id)->where('user_id', Auth::id())->select('workspaces.*', 'members.role')->first();
        abort_unless($row && in_array($row->role, $roles, true), 404);

        return $row;
    }

    public function environment(string $id, array $roles = ['owner', 'editor']): object
    {
        $row = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('environments.id', $id)->select('environments.*', 'projects.workspace_id')->first();
        abort_unless($row, 404);
        $this->workspace($row->workspace_id, $roles);

        return $row;
    }

    public function entry(string $id, array $roles = ['owner', 'editor', 'viewer']): object
    {
        $row = DB::table('entries')->where('id', $id)->first();
        abort_unless($row, 404);
        $scope = $this->environment($row->environment_id, $roles);
        $row->workspace_id = $scope->workspace_id;

        return $row;
    }

    public function recent(Request $r): void
    {
        if (time() - (int) $r->session()->get('confirmed_at', 0) >= 300) {
            throw new ConfirmationRequired;
        }
    }

    public function audit(?string $workspace, string $event, ?string $subject = null, string $outcome = 'ok', ?string $actor = null): void
    {
        DB::table('audit')->insert(['workspace_id' => $workspace, 'actor' => $actor ?? 'user:'.Auth::id(),
            'event' => $event, 'subject' => $subject, 'outcome' => $outcome, 'created_at' => now()]);
    }
}
