<?php

namespace App\Vault;

use App\Http\Account;
use App\Http\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PendingAction
{
    public const KEY = 'pending_action';

    public static function remember(Request $r): ?array
    {
        $r->session()->forget(self::KEY);
        $path = $r->getRequestUri();
        $origin = $r->headers->get('origin');
        if (! $r->isMethod('POST') || $r->request->has('confirm_password')
            || $r->getSchemeAndHttpHost() !== rtrim(config('app.url'), '/')
            || ($r->headers->has('referer') && ! str_starts_with($r->headers->get('referer'), $r->getSchemeAndHttpHost().'/'))
            || ($origin !== null && $origin !== $r->getSchemeAndHttpHost())
            || $path !== $r->getPathInfo() || Account::safePath($path) !== $path
            || ! preg_match('~^/[A-Za-z0-9_/-]+$~D', $path)
            || $r->route()?->getControllerClass() !== Workspace::class) {
            return null;
        }

        $method = $r->route()->getActionMethod();
        $fields = [];
        foreach ($r->request->all() as $key => $value) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $key) || preg_match('/password|secret|import|code|token/i', $key)) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $index => $item) {
                    if (is_int($index) && $index >= 0 && (is_null($item) || is_scalar($item)) && strlen((string) $item) <= 4096) {
                        $fields[$key.'['.$index.']'] = (string) $item;
                    }
                }
            } elseif ((is_null($value) || is_scalar($value)) && strlen((string) $value) <= 4096) {
                $fields[$key] = (string) $value;
            }
        }

        $name = $fields['name'] ?? '';
        $space = $r->route('workspace');
        $id = $r->route('id');
        $label = match ($method) {
            'workspace' => "Add workspace $name",
            'project' => "Add project $name",
            'environment' => "Add environment $name",
            'renameProject' => "Rename project to $name",
            'updateEnvironment' => "Update environment $name",
            'deleteProject' => 'Remove project '.DB::table('projects')->where('workspace_id', $space)->where('id', $r->route('project'))->value('name'),
            'deleteEnvironment' => 'Remove environment '.DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')->where('projects.workspace_id', $space)->where('environments.id', $r->route('environment'))->value('environments.name'),
            'archive', 'unarchive', 'restoreVersion', 'destroy', 'reveal' => self::entryLabel($method, $id, $fields['version'] ?? ''),
            'revoke' => self::revokeLabel($space, $r->route('kind'), $id),
            'machine' => "Add agent $name",
            'saveType' => "Save type $name",
            'deleteType' => 'Remove type '.$r->route('key'),
            'template' => 'Save environment template',
            'grant', 'decision' => 'Continue the action you started',
            default => null,
        };
        if ($label === null) {
            return null;
        }
        $action = compact('path', 'fields', 'label') + ['return' => Account::returnPath($r), 'nonce' => Str::random(32), 'expires' => now()->timestamp + 600];
        $r->session()->put(self::KEY, $action);

        return $action;
    }

    private static function entryLabel(string $method, string $id, string $version): string
    {
        try {
            $name = app(Access::class)->entry($id)->name;
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return 'Continue the action you started';
        }
        return match ($method) {
            'archive' => "Archive $name",
            'unarchive' => "Unarchive $name",
            'restoreVersion' => "Restore version $version of $name",
            'destroy' => "Delete $name",
            'reveal' => "Reveal $name",
        };
    }

    private static function revokeLabel(string $space, string $kind, string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $machines = DB::table('machines')->where('machines.workspace_id', $space);
        if ($kind === 'machines') {
            return 'Revoke agent '.$machines->where('machines.id', $id)->value('name');
        }
        if ($kind !== 'grants') {
            return null;
        }
        $grant = $machines->join('grants', 'grants.machine_id', '=', 'machines.id')
            ->where('grants.id', $id)->first(['machines.name', 'grants.action']);
        return $grant ? "Revoke grant {$grant->action} for {$grant->name}" : 'Revoke grant';
    }

    public static function forConfirmation(Request $r): ?array
    {
        $action = $r->session()->get(self::KEY);
        if (! $action || $action['expires'] <= now()->timestamp) {
            $r->session()->forget(self::KEY);
            return null;
        }
        return $r->input('pending') === $action['nonce'] ? $action : null;
    }
}
