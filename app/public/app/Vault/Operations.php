<?php

namespace App\Vault;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class Operations
{
    public function __construct(private Access $access) {}

    public function definitions(): array
    {
        try {
            $config = json_decode(file_get_contents(config('vault.connectors')), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(503);
        }

        return $config['actions'] ?? [];
    }

    private function grantQuery()
    {
        return DB::table('grants')->join('machines', 'machines.id', '=', 'grants.machine_id')
            ->join('entries', 'entries.id', '=', 'grants.entry_id')->join('environments', 'environments.id', '=', 'entries.environment_id')
            ->join('projects', 'projects.id', '=', 'environments.project_id')
            ->whereColumn('projects.workspace_id', 'machines.workspace_id')->whereNull('grants.revoked_at')->whereNull('machines.revoked_at')
            ->where('grants.expires_at', '>', now())->where('machines.expires_at', '>', now())->where('entries.state', 'active');
    }

    public function catalog(object $machine, array $filters = []): array
    {
        $filters = Validator::make($filters, [
            'query' => 'sometimes|string|max:100', 'project_id' => 'sometimes|uuid', 'environment_id' => 'sometimes|uuid',
            'type' => 'sometimes|in:login,api_key,database,dynamic_database,ssh,secure_note',
            'page' => 'sometimes|integer|min:1|max:10000', 'per_page' => 'sometimes|integer|min:1|max:100',
        ])->validate();
        $query = $this->grantQuery()->where('machines.id', $machine->id)->whereIn('grants.action', array_keys($this->definitions()));
        if (isset($filters['query'])) {
            $query->where('entries.alias', 'like', '%'.$filters['query'].'%');
        }
        foreach (['project_id' => 'projects.id', 'environment_id' => 'environments.id', 'type' => 'entries.type'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        $page = (int) ($filters['page'] ?? 1);
        $limit = (int) ($filters['per_page'] ?? 50);
        $rows = $query->select('entries.id', 'entries.alias', 'entries.type', 'grants.action', 'projects.id as project_id', 'projects.name as project', 'environments.id as environment_id', 'environments.name as environment')
            ->orderBy('entries.id')->orderBy('grants.action')->offset(($page - 1) * $limit)->limit($limit + 1)->get();

        return ['entries' => $rows->take($limit)->map(fn ($row) => (array) $row)->values()->all(), 'next_page' => $rows->count() > $limit ? $page + 1 : null];
    }

    public function authorized(string $id, bool $includePending = false): ?object
    {
        $op = DB::table('operations')->where('id', $id)->where('expires_at', '>', now())->whereIn('state', $includePending ? ['pending', 'approved', 'running', 'requires_user_action'] : ['approved', 'running', 'requires_user_action'])->first();
        if (! $op) {
            return null;
        }
        $grant = $this->grantQuery()->where('grants.id', $op->grant_id)->where('machines.id', $op->machine_id)
            ->where('entries.id', $op->entry_id)->where('entries.version', $op->version)->where('grants.action', $op->action)->first();

        return $grant && isset($this->definitions()[$op->action]) ? $op : null;
    }

    public function broker(string $method, string $path, array $body = []): array
    {
        try {
            $response = Http::baseUrl(config('vault.broker_url'))->withToken(trim(file_get_contents(config('vault.broker_key'))))
                ->timeout(8)->connectTimeout(2)->withoutRedirecting()->send($method, $path, ['json' => $body]);
            if (! $response->successful()) {
                abort(503);
            }

            return $response->json();
        } catch (\Throwable) {
            abort(503);
        }
    }

    public function execute(object $machine, string $entry, string $action, string $requestId): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $requestId), 422);
        $op = DB::transaction(function () use ($machine, $entry, $action, $requestId) {
            DB::table('machines')->where('id', $machine->id)->lockForUpdate()->first();
            $prior = DB::table('operations')->where('machine_id', $machine->id)->where('request_id', $requestId)->first();
            if ($prior) {
                abort_unless($prior->entry_id === $entry && $prior->action === $action, 409);

                return $prior;
            }
            $grant = $this->grantQuery()->where('machines.id', $machine->id)->where('entries.id', $entry)->where('grants.action', $action)
                ->select('grants.*', 'entries.version')->first();
            abort_unless($grant && isset($this->definitions()[$action]), 404);
            abort_if(DB::table('operations')->where('machine_id', $machine->id)->whereIn('state', ['pending', 'approved', 'running', 'requires_user_action'])->where('expires_at', '>', now())->count() >= 5, 429);
            $id = (string) Str::uuid();
            DB::table('operations')->insert(['id' => $id, 'machine_id' => $machine->id, 'grant_id' => $grant->id, 'entry_id' => $entry, 'action' => $action,
                'version' => $grant->version, 'state' => $grant->approval ? 'pending' : 'approved', 'request_id' => $requestId,
                'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
            $this->access->audit($machine->workspace_id, 'operation.request', $id, 'ok', 'machine:'.$machine->id);

            return DB::table('operations')->where('id', $id)->first();
        });

        return $this->status($machine, $op->id);
    }

    public function status(object $machine, string $id): array
    {
        $op = DB::table('operations')->where('id', $id)->where('machine_id', $machine->id)->first();
        abort_unless($op, 404);
        if (in_array($op->state, ['pending', 'approved', 'running', 'requires_user_action'])) {
            if (strtotime($op->expires_at) <= time()) {
                $this->terminal($op, 'expired', $machine);
            } elseif (! $this->authorized($id, true)) {
                $this->terminal($op, 'revoked', $machine);
            } elseif ($op->state === 'approved') {
                // Claim once before dispatch. A crash in this window fails closed rather than replaying side effects.
                $claimed = DB::table('operations')->where('id', $id)->where('state', 'approved')->update(['state' => 'running', 'updated_at' => now()]);
                if ($claimed) {
                    $this->broker('POST', '/operations', ['id' => $id, 'entry_id' => $op->entry_id, 'version' => $op->version, 'action' => $op->action]);
                }
            } elseif (in_array($op->state, ['running', 'requires_user_action'])) {
                $result = $this->broker('GET', '/operations/'.$id);
                $state = $result['state'] ?? 'failed';
                abort_unless(in_array($state, ['running', 'requires_user_action', 'succeeded', 'failed', 'expired', 'revoked']), 503);
                if (in_array($state, ['succeeded', 'failed', 'expired', 'revoked'])) {
                    $this->terminal($op, $state, $machine);
                } else {
                    DB::table('operations')->where('id', $id)->whereIn('state', ['running', 'requires_user_action'])->update(['state' => $state, 'updated_at' => now()]);
                }
            }
        }
        $op = DB::table('operations')->where('id', $id)->first();

        return ['id' => $id, 'state' => $op->state, 'ok' => $op->state === 'succeeded', 'expires_at' => $op->expires_at];
    }

    private function terminal(object $op, string $state, object $machine): void
    {
        DB::transaction(function () use ($op, $state, $machine) {
            $updated = DB::table('operations')->where('id', $op->id)->whereIn('state', ['pending', 'approved', 'running', 'requires_user_action'])->update(['state' => $state, 'updated_at' => now()]);
            if ($updated) {
                $this->access->audit($machine->workspace_id, 'operation.finish', $op->id, $state, 'machine:'.$machine->id);
            }
        });
        if (in_array($state, ['revoked', 'expired'])) {
            $this->broker('POST', '/operations/'.$op->id.'/cancel');
        }
    }

    public function cancel(object $machine, string $id): array
    {
        $op = DB::table('operations')->where('id', $id)->where('machine_id', $machine->id)->first();
        abort_unless($op, 404);
        $this->terminal($op, 'revoked', $machine);

        return $this->status($machine,$id);
    }
}
