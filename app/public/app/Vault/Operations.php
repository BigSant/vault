<?php

namespace App\Vault;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class Operations
{
    /** Connector kinds that leave a file in the handoff directory for an executor identity. */
    public const HANDOFF_KINDS = ['storage_state'];

    /** The broker's longest login; a handoff operation may run this long before its lease starts. */
    public const LOGIN_SECONDS = 180;

    /** Handoffs one executor may start per hour. */
    public const HANDOFFS_PER_HOUR = 60;

    /** Why a file was removed, by the final state the removal gives. */
    private const REMOVAL = ['succeeded' => 'release', 'expired' => 'ttl', 'revoked' => 'revoke', 'failed' => 'restart'];

    public function __construct(private Access $access) {}

    public static function isHandoff(?array $definition): bool
    {
        return in_array($definition['kind'] ?? null, self::HANDOFF_KINDS, true);
    }

    /** ttl_seconds of a handoff connector: 1-1800, 900 when left out; null when the operator wrote something else. */
    public static function leaseSeconds(array $definition): ?int
    {
        $ttl = $definition['ttl_seconds'] ?? 900;

        return is_int($ttl) && $ttl >= 1 && $ttl <= 1800 ? $ttl : null;
    }

    /** Whether a handoff connector accepts this entry type (its `entry_types` list, fixed by the operator). */
    public static function acceptsType(array $definition, ?string $type): bool
    {
        return is_array($definition['entry_types'] ?? null) && in_array($type, $definition['entry_types'], true);
    }

    /** @return list<string> the configured actions of a handoff kind */
    public function handoffActions(): array
    {
        return array_keys(array_filter($this->definitions(), fn ($d) => self::isHandoff($d)));
    }

    public function definitions(): array
    {
        try {
            $config = json_decode(file_get_contents(config('vault.connectors')), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(503);
        }

        // onetimesecret.link is Vault's own action (Links): it never reaches the broker and needs no connector.
        return array_diff_key($config['actions'] ?? [], [Links::ACTION => true]) + [Links::ACTION => ['kind' => 'onetimesecret']];
    }

    /** Active grants of this agent on active entries of its workspace. */
    public function grantRows(object $machine)
    {
        return $this->grantQuery()->where('machines.id', $machine->id);
    }

    private function grantQuery()
    {
        return DB::table('grants')->join('machines', 'machines.id', '=', 'grants.machine_id')
            ->join('entries', 'entries.id', '=', 'grants.entry_id')->join('environments', 'environments.id', '=', 'entries.environment_id')
            ->join('projects', 'projects.id', '=', 'entries.project_id')
            ->whereColumn('projects.workspace_id', 'machines.workspace_id')->whereNull('grants.revoked_at')->whereNull('machines.revoked_at')
            ->where('grants.expires_at', '>', now())->where('machines.expires_at', '>', now())->where('entries.state', 'active');
    }

    public function catalog(object $machine, array $filters = []): array
    {
        $filters = Validator::make($filters, [
            'query' => 'sometimes|string|max:100', 'project_id' => 'sometimes|uuid', 'environment_id' => 'sometimes|uuid',
            'type' => 'sometimes|string|max:40',
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
            ->where('entries.id', $op->entry_id)->where('entries.version', $op->version)->where('grants.action', $op->action)
            ->select('grants.id', 'machines.executor', 'entries.type')->first();
        $definition = $this->definitions()[$op->action] ?? null;
        // A handoff is only ever for an executor and an entry type the connector names.
        if ($grant && self::isHandoff($definition) && (! $grant->executor || ! self::acceptsType($definition, $grant->type))) {
            return null;
        }

        return $grant && $definition ? $op : null;
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
        // A one-time link carries content: it is asked for with vault_link_create, never with execute.
        abort_if($action === Links::ACTION, 422);
        $op = $this->open($machine, $entry, $action, $requestId);

        return $this->status($machine, $op->id);
    }

    /**
     * Opens a request (or returns the one this request_id already opened). $created runs inside the same transaction
     * with the new row, so whatever belongs to the request is written together with it.
     */
    public function open(object $machine, string $entry, string $action, string $requestId, ?\Closure $created = null): object
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $requestId), 422);

        return DB::transaction(function () use ($machine, $entry, $action, $requestId, $created) {
            DB::table('machines')->where('id', $machine->id)->lockForUpdate()->first();
            $prior = DB::table('operations')->where('machine_id', $machine->id)->where('request_id', $requestId)->first();
            if ($prior) {
                abort_unless($prior->entry_id === $entry && $prior->action === $action, 409);

                return $prior;
            }
            $grant = $this->grantQuery()->where('machines.id', $machine->id)->where('entries.id', $entry)->where('grants.action', $action)
                ->select('grants.*', 'entries.version', 'entries.type as entry_type')->first();
            $definition = $this->definitions()[$action] ?? null;
            abort_unless($grant && $definition, 404);
            if (self::isHandoff($definition)) {
                // Only an executor identity ever receives a handoff; to anyone else the action does not exist.
                abort_unless($machine->executor ?? false, 404);
                abort_unless(self::acceptsType($definition, $grant->entry_type), 422);
                abort_if(self::leaseSeconds($definition) === null, 503);
                // One live handoff per entry, whoever asks: the entry's row lock orders concurrent requests.
                DB::table('entries')->where('id', $entry)->lockForUpdate()->first();
                abort_if(DB::table('operations')->where('entry_id', $entry)->whereIn('action', $this->handoffActions())
                    ->whereIn('state', ['pending', 'approved', 'running', 'requires_user_action'])->where('expires_at', '>', now())->exists(), 429);
                abort_if(DB::table('operations')->where('machine_id', $machine->id)->whereIn('action', $this->handoffActions())
                    ->where('created_at', '>', now()->subHour())->count() >= self::HANDOFFS_PER_HOUR, 429);
            }
            abort_if(DB::table('operations')->where('machine_id', $machine->id)->whereIn('state', ['pending', 'approved', 'running', 'requires_user_action'])->where('expires_at', '>', now())->count() >= 5, 429);
            $id = (string) Str::uuid();
            DB::table('operations')->insert(['id' => $id, 'machine_id' => $machine->id, 'grant_id' => $grant->id, 'entry_id' => $entry, 'action' => $action,
                // A one-time link with an entry's values is approved by an owner every time, whatever the grant says.
                'version' => $grant->version, 'state' => $grant->approval || $action === Links::ACTION ? 'pending' : 'approved', 'request_id' => $requestId,
                'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
            $this->access->audit($machine->workspace_id, 'operation.request', $id, 'ok', 'machine:'.$machine->id);
            $op = DB::table('operations')->where('id', $id)->first();
            if ($created) {
                $created($op);
            }

            return $op;
        });
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
                // A handoff stays authorized through the login and its whole lease; the broker ends the lease itself.
                $definition = $this->definitions()[$op->action] ?? null;
                $until = self::isHandoff($definition) && self::leaseSeconds($definition) ? ['expires_at' => now()->addSeconds(self::LOGIN_SECONDS + self::leaseSeconds($definition))] : [];
                $claimed = DB::table('operations')->where('id', $id)->where('state', 'approved')->update(['state' => 'running', 'updated_at' => now()] + $until);
                if ($claimed && $op->action === Links::ACTION) {
                    $this->terminal($op, app(Links::class)->send($op), $machine);
                } elseif ($claimed) {
                    $this->broker('POST', '/operations', ['id' => $id, 'entry_id' => $op->entry_id, 'version' => $op->version, 'action' => $op->action]);
                }
            } elseif ($op->action === Links::ACTION && $op->state === 'running') {
                // Sending a link takes seconds and happens inside the claim. Still running long after: that claim
                // crashed. Fail closed; the content is never sent twice.
                if (strtotime($op->updated_at) <= time() - 60) {
                    $this->terminal($op, 'failed', $machine);
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
        $answer = ['id' => $id, 'state' => $op->state, 'ok' => $op->state === 'succeeded', 'expires_at' => $op->expires_at];
        // A handoff also says when its file is ready (and until when). Never the path, never a value.
        if (in_array($op->action, $this->handoffActions(), true)) {
            $ready = $op->state === 'running' && $op->handoff === 'written';
            $answer += ['ready' => $ready, 'lease_expires_at' => $ready ? $op->lease_expires_at : null];
        }

        return $answer;
    }

    /**
     * @param  bool  $tellBroker  false when the broker itself reported the end (its own callback must not wait on it)
     */
    public function terminal(object $op, string $state, object $machine, bool $tellBroker = true): void
    {
        DB::transaction(function () use ($op, $state, $machine) {
            $updated = DB::table('operations')->where('id', $op->id)->whereIn('state', ['pending', 'approved', 'running', 'requires_user_action'])->update(['state' => $state, 'updated_at' => now()]);
            if ($updated) {
                $this->access->audit($machine->workspace_id, 'operation.finish', $op->id, $state, 'machine:'.$machine->id);
            }
        });
        if ($tellBroker && in_array($state, ['revoked', 'expired']) && $op->action !== Links::ACTION) {
            // The broker removes a handoff file before it answers.
            $this->broker('POST', '/operations/'.$op->id.'/cancel');
        }
        $final = DB::table('operations')->where('id', $op->id)->value('state');
        if (isset(self::REMOVAL[$final])) {
            $this->handoffRemoved($op->id, self::REMOVAL[$final]);
        }
    }

    /** Records once that an operation's handoff file is gone. Nothing happens for an operation without one. */
    public function handoffRemoved(string $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            $op = DB::table('operations')->where('id', $id)->lockForUpdate()->first();
            if ($op && $op->handoff === 'written' && DB::table('operations')->where('id', $id)->where('handoff', 'written')->update(['handoff' => 'removed', 'updated_at' => now()])) {
                $machine = DB::table('machines')->where('id', $op->machine_id)->first();
                $this->access->audit($machine?->workspace_id, 'handoff.removed', $id, $reason, 'machine:'.$op->machine_id);
            }
        });
    }

    /**
     * The broker's report on a handoff file. written: recorded only for a running, still authorized handoff (else
     * 409, and the broker removes the file at once). removed: ends the operation with the state the reason gives.
     */
    public function handoffEvent(string $id, array $body): array
    {
        $op = DB::table('operations')->where('id', $id)->first();
        abort_unless($op && in_array($op->action, $this->handoffActions(), true), 404);
        $machine = DB::table('machines')->where('id', $op->machine_id)->first();
        if (($body['event'] ?? null) === 'written') {
            $until = is_string($body['lease_expires_at'] ?? null) ? strtotime($body['lease_expires_at']) : false;
            abort_unless($until !== false && $until > time() && $until <= strtotime($op->expires_at) + 5, 422);
            $recorded = DB::transaction(function () use ($id, $until, $machine) {
                $updated = $this->authorized($id) ? DB::table('operations')->where('id', $id)->where('state', 'running')->whereNull('handoff')
                    ->update(['handoff' => 'written', 'lease_expires_at' => date('Y-m-d H:i:s', $until), 'updated_at' => now()]) : 0;
                if ($updated) {
                    $this->access->audit($machine->workspace_id, 'handoff.written', $id, 'ok', 'machine:'.$machine->id);
                }

                return (bool) $updated;
            });
            abort_unless($recorded, 409);

            return ['ok' => true];
        }
        $reason = $body['reason'] ?? null;
        abort_unless(($body['event'] ?? null) === 'removed' && in_array($reason, self::REMOVAL, true), 422);
        $this->terminal($op, array_search($reason, self::REMOVAL, true), $machine, false);
        $this->handoffRemoved($id, $reason);

        return ['ok' => true];
    }

    /** The executor is done with the file: the broker removes it and the operation succeeds. */
    public function release(object $machine, string $id): array
    {
        $op = DB::table('operations')->where('id', $id)->where('machine_id', $machine->id)->first();
        abort_unless($op && in_array($op->action, $this->handoffActions(), true), 404);
        $current = $this->status($machine, $id);
        if (in_array($current['state'], ['succeeded', 'failed', 'expired', 'revoked'], true)) {
            return $current;
        }
        abort_unless($current['ready'], 409);
        $result = $this->broker('POST', '/operations/'.$id.'/release');
        $state = $result['state'] ?? 'failed';
        abort_unless(in_array($state, ['succeeded', 'failed', 'expired', 'revoked'], true), 503);
        $this->terminal($op, $state, $machine, false);

        return $this->status($machine, $id);
    }

    public function cancel(object $machine, string $id): array
    {
        $op = DB::table('operations')->where('id', $id)->where('machine_id', $machine->id)->first();
        abort_unless($op, 404);
        $this->terminal($op, 'revoked', $machine);

        return $this->status($machine,$id);
    }
}
