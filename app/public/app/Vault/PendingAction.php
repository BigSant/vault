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
            'workspace' => self::label('Add workspace', $name),
            'project' => self::label('Add project', $name),
            'environment' => self::label('Add environment', $name),
            'renameProject' => self::label('Rename project to', $name),
            'updateEnvironment' => self::label('Update environment', $name),
            'deleteProject' => self::label('Remove project', DB::table('projects')->where('workspace_id', $space)->where('id', $r->route('project'))->value('name')),
            'deleteEnvironment' => self::label('Remove environment', DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')->where('projects.workspace_id', $space)->where('environments.id', $r->route('environment'))->value('environments.name')),
            'archive', 'unarchive', 'restoreVersion', 'destroy', 'reveal' => self::entryLabel($method, $id, $fields['version'] ?? ''),
            'revoke' => self::revokeLabel($space, $r->route('kind'), $id),
            'machine' => self::label('Add agent', $name),
            'saveType' => self::label('Save entry type', $name),
            'deleteType' => self::label('Remove entry type', DB::table('entry_types')->where('workspace_id', $space)->where('key', $r->route('key'))->value('name')),
            'template' => self::label('Save environment template'),
            'grant', 'decision' => self::accessLabel($method, $space, $id, $fields),
            default => null,
        };
        if ($label === null) {
            return null;
        }
        $action = compact('path', 'fields', 'label') + ['return' => Account::returnPath($r), 'nonce' => Str::random(32), 'expires' => now()->timestamp + 600];
        $r->session()->put(self::KEY, $action);

        return $action;
    }

    private static function label(string $verb, ?string $name = null, string $suffix = ''): array
    {
        return compact('verb', 'name', 'suffix');
    }

    private static function accessLabel(string $method, string $space, ?string $id, array $fields): array
    {
        $entries = DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')
            ->join('projects', 'projects.id', '=', 'environments.project_id')->where('projects.workspace_id', $space);
        $machines = DB::table('machines')->where('workspace_id', $space);
        if ($method === 'grant') {
            if (Str::isUuid($fields['entry_id'] ?? '') && Str::isUuid($fields['machine_id'] ?? '')) {
                $entry = $entries->where('entries.id', $fields['entry_id'])->value('entries.name');
                $agent = $machines->where('id', $fields['machine_id'])->value('name');
                if ($entry !== null && $agent !== null && ! empty($fields['action'])) {
                    return self::label('Add grant', "{$fields['action']} for $agent on $entry");
                }
            }
        } elseif (Str::isUuid($id) && in_array($fields['decision'] ?? '', ['approve', 'deny'], true)) {
            $op = $entries->join('operations', 'operations.entry_id', '=', 'entries.id')
                ->whereIn('operations.machine_id', $machines->select('id'))->where('operations.id', $id)
                ->first(['operations.action', 'entries.name']);
            if ($op) {
                return self::label($fields['decision'] === 'approve' ? 'Allow once:' : 'Deny:', "$op->action on $op->name");
            }
        }
        return self::label('Continue');
    }

    private static function entryLabel(string $method, string $id, string $version): array
    {
        try {
            $name = app(Access::class)->entry($id)->name;
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return self::label('Continue');
        }
        return match ($method) {
            'archive' => self::label("Archive", $name),
            'unarchive' => self::label('Restore', $name, 'from archive'),
            'restoreVersion' => self::label("Restore version $version of", $name),
            'destroy' => self::label('Destroy', $name),
            'reveal' => self::label("Reveal", $name),
        };
    }

    private static function revokeLabel(string $space, string $kind, string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $machines = DB::table('machines')->where('machines.workspace_id', $space);
        if ($kind === 'machines') {
            return self::label('Revoke agent', $machines->where('machines.id', $id)->value('name'));
        }
        if ($kind !== 'grants') {
            return null;
        }
        $grant = $machines->join('grants', 'grants.machine_id', '=', 'machines.id')
            ->where('grants.id', $id)->first(['machines.name', 'grants.action']);
        return self::label('Revoke grant', $grant ? "{$grant->action} for {$grant->name}" : null);
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
