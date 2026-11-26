<?php

namespace App\Vault;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Catalog
{
    /** Non-secret entry metadata tracked in the change log. */
    private const META = ['name', 'alias', 'tags', 'rotate_at'];

    public function __construct(private Secrets $secrets, private Access $access, private EntryTypes $types) {}

    public function validate(array $input): array
    {
        if (! isset($input['secret']) && isset($input['secret_fields']) && is_array($input['secret_fields'])) {
            $input['secret'] = json_encode(array_filter($input['secret_fields'], fn ($v) => is_string($v) && $v !== ''), JSON_THROW_ON_ERROR);
        }
        // No alias given: it is made from the name (as the add form shows while typing).
        if ((! isset($input['alias']) || (is_string($input['alias']) && trim($input['alias']) === '')) && is_string($input['name'] ?? null)) {
            $input['alias'] = self::aliasFor($input['name']);
        }
        $data = Validator::make($input, [
            'project_id' => 'required|uuid', 'environment_id' => 'required|uuid', 'name' => 'required|string|max:160', 'alias' => 'required|string|max:100|regex:/^[a-zA-Z0-9_. -]+$/',
            // Type keys are lowercase only: a case-insensitive collation must never match a differently-cased key.
            'type' => ['required', 'string', 'regex:'.EntryTypes::KEY], 'tags' => 'nullable|string|max:200',
            'secret' => 'required|string|max:65536', 'rotate_at' => 'nullable|date_format:Y-m-d',
        ])->validate();
        $payload = json_decode($data['secret'], true);
        if (! is_array($payload) || array_is_list($payload) || count($payload) > 40) {
            throw ValidationException::withMessages(['secret' => 'Enter a JSON object with 1 to 40 secret fields.']);
        }
        foreach ($payload as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z_][a-z0-9_]{0,39}$/D', $key) || ! is_string($value) || strlen($value) > 16000) {
                throw ValidationException::withMessages(['secret' => 'Check the secret field names and enter text values of up to 16000 bytes.']);
            }
        }
        $data['payload'] = $payload;
        unset($data['secret']);

        return $data;
    }

    /** The project and the environment must both be of the workspace. Unknown or foreign ones read as one message. */
    private function sameWorkspace(string $workspace, array $data): void
    {
        $project = DB::table('projects')->where('id', $data['project_id'])->value('workspace_id');
        $environment = DB::table('environments')->where('id', $data['environment_id'])->value('workspace_id');
        if ($project !== $workspace || $environment !== $workspace) {
            throw ValidationException::withMessages($project !== $workspace ? ['project_id' => 'Choose a project of this workspace.'] : ['environment_id' => 'Choose an environment of this workspace.']);
        }
    }

    /** An alias from a name: lowercase ASCII words joined by "-" ("Šlapias Admin" → "slapias-admin"); "entry" when nothing is left. */
    public static function aliasFor(string $name): string
    {
        $slug = trim(substr(\Illuminate\Support\Str::slug($name), 0, 100), '-');

        return $slug === '' ? 'entry' : $slug;
    }

    /** The type must exist in the entry's workspace and every required field must have a value. */
    private function checkType(string $workspace, #[\SensitiveParameter] array $data): object
    {
        $type = $this->types->find($workspace, $data['type']);
        if (! $type) {
            throw ValidationException::withMessages(['type' => 'This entry type does not exist in this workspace.']);
        }
        $missing = array_filter($type->fields, fn ($f) => $f['required'] && ($data['payload'][$f['key']] ?? '') === '');
        if ($missing) {
            throw ValidationException::withMessages(['secret' => 'Fill in the required fields: '.implode(', ', array_column($missing, 'label')).'.']);
        }

        return $type;
    }

    public function create(array $input): string
    {
        $data = $this->validate($input);
        $scope = $this->access->project($data['project_id']);
        $this->sameWorkspace($scope->workspace_id, $data);

        return DB::transaction(function () use ($data, $scope) {
            // The project and the environment are locked like their removal locks them: neither can go before the insert commits.
            abort_unless(DB::table('projects')->where('id', $data['project_id'])->lockForUpdate()->exists()
                && DB::table('environments')->where('id', $data['environment_id'])->lockForUpdate()->exists(), 409);
            // Checked under the lock type deletion also takes, so the type cannot vanish before the insert commits.
            $this->types->lock($scope->workspace_id);
            $type = $this->checkType($scope->workspace_id, $data);
            $data['type'] = $type->key;
            $id = (string) Str::uuid();
            // Write audit intent first. A failed DB transaction can leave only an unreachable backend orphan.
            $this->access->audit($scope->workspace_id, 'entry.create', $id);
            $version = $this->secrets->write('entries/'.$id, $data['payload'], 0);
            $this->record($id, $version, 'create', ['fields' => $this->named(['added' => array_keys($data['payload'])], $type), 'meta' => []]);
            unset($data['payload']);
            DB::table('entries')->insert([...$data, 'tags' => $data['tags'] ?? '', 'rotate_at' => $data['rotate_at'] ?? null,
                'id' => $id, 'version' => $version, 'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    public function update(string $id, array $input, ?int $restoredFrom = null): void
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        DB::transaction(function () use ($entry, $id, $input, $restoredFrom) {
            $current = DB::table('entries')->where('id', $id)->lockForUpdate()->first();
            $previous = $this->secrets->read('entries/'.$id, $current->version);
            $type = $this->types->find($entry->workspace_id, $current->type);
            $required = $type ? array_column(array_filter($type->fields, fn ($f) => $f['required']), 'key') : [];
            if (! isset($input['secret'])) {
                $fields = $input['secret_fields'] ?? [];
                if (! is_array($fields) || array_filter($fields, fn ($value) => ! is_string($value) && $value !== null)) {
                    throw ValidationException::withMessages(['secret_fields' => 'Enter text for each secret field.']);
                }
                $payload = array_replace($previous, array_filter($fields, fn ($value) => is_string($value) && $value !== ''));
                $clear = $input['clear_fields'] ?? [];
                if (! is_array($clear) || array_filter($clear, fn ($field) => ! is_string($field))) {
                    throw ValidationException::withMessages(['clear_fields' => 'Choose fields to clear.']);
                }
                if (array_intersect($clear, $required)) {
                    throw ValidationException::withMessages(['clear_fields' => 'Required fields cannot be cleared.']);
                }
                foreach ($clear as $field) {
                    unset($payload[$field]);
                }
                $input['secret'] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            $data = $this->validate($input);
            // No moving, type changes or archive resurrection through a stale edit form.
            if ($data['project_id'] !== $current->project_id || $data['environment_id'] !== $current->environment_id || $data['type'] !== $current->type || $current->state !== 'active') {
                throw ValidationException::withMessages(['entry' => 'Only active entries can be edited, with their existing project, environment and type.']);
            }
            $carried = [];
            if ($restoredFrom !== null) {
                // The restored version may predate a field the type now requires: keep that field's current value
                // instead of refusing the restore. The change record names which fields were kept.
                foreach ($required as $key) {
                    if (($data['payload'][$key] ?? '') === '' && ($previous[$key] ?? '') !== '') {
                        $data['payload'][$key] = $previous[$key];
                        $carried[] = $key;
                    }
                }
            }
            $type = $this->checkType($entry->workspace_id, $data);
            $changes = $this->diff($previous, $data['payload'], $current, $data, $type);
            if ($restoredFrom !== null) {
                $changes['restored_from'] = $restoredFrom;
                if ($carried) {
                    $changes['carried'] = $carried;
                }
            }
            $this->access->audit($entry->workspace_id, 'entry.rotate', $id);
            $version = $this->secrets->write('entries/'.$id, $data['payload'], $current->version);
            $this->record($id, $version, $restoredFrom !== null ? 'restore' : 'update', $changes);
            unset($data['payload']);
            DB::table('entries')->where('id', $id)->update([...$data, 'tags' => $data['tags'] ?? '', 'rotate_at' => $data['rotate_at'] ?? null, 'version' => $version, 'updated_at' => now()]);
            DB::table('operations')->where('entry_id', $id)->whereIn('state', ['pending', 'approved'])->update(['state' => 'revoked', 'updated_at' => now()]);
        });
    }

    /** Field names (never values) and non-secret metadata that differ between two versions. */
    private function diff(#[\SensitiveParameter] array $old, #[\SensitiveParameter] array $new, object $current, #[\SensitiveParameter] array $data, object $type): array
    {
        $fields = ['added' => array_keys(array_diff_key($new, $old)), 'removed' => array_keys(array_diff_key($old, $new)), 'changed' => []];
        foreach (array_intersect_key($new, $old) as $key => $value) {
            if (! hash_equals($old[$key], $value)) {
                $fields['changed'][] = $key;
            }
        }
        $meta = [];
        foreach (self::META as $key) {
            $before = $key === 'rotate_at' ? ($current->rotate_at ? substr((string) $current->rotate_at, 0, 10) : null) : ($current->{$key} ?? '');
            $after = $key === 'rotate_at' ? ($data['rotate_at'] ?? null) : ($data[$key] ?? '');
            if ((string) $before !== (string) $after) {
                $meta[$key] = [$before, $after];
            }
        }

        return ['fields' => $this->named($fields, $type), 'meta' => $meta];
    }

    /**
     * Viewers read the change log without a reveal, and payload keys the type does not declare are free text
     * chosen by whoever imported the entry. Only declared keys are named; the rest are reduced to counts.
     */
    private function named(array $fields, ?object $type): array
    {
        $declared = $type ? array_column($type->fields, 'key') : [];
        $named = [];
        foreach (['added', 'changed', 'removed'] as $group) {
            $keys = array_values(array_filter((array) ($fields[$group] ?? []), 'is_string'));
            $named[$group] = array_values(array_intersect($keys, $declared));
            $named['other_'.$group] = count($keys) - count($named[$group]) + (int) ($fields['other_'.$group] ?? 0);
        }

        return $named;
    }

    private function record(string $id, int $version, string $event, array $changes): void
    {
        DB::table('entry_changes')->insert(['entry_id' => $id, 'version' => $version, 'event' => $event, 'user_id' => Auth::id(),
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    /**
     * The newest $limit versions, newest first, with who changed what; $older reports whether earlier versions exist.
     * Versions from before the change log have no details. Field names are filtered against the type's current
     * fields on every read, so rows written before that rule (or naming a since-removed field) only add to the counts.
     */
    public function history(object $entry, int $limit = 50, ?bool &$older = null, int $page = 1): array
    {
        $limit = max(1, $limit);
        $latest = (int) $entry->version - (max(1, $page) - 1) * $limit;
        if ($latest < 1) {
            $older = false;

            return [];
        }
        $oldest = max(1, $latest - $limit + 1);
        $older = $oldest > 1;
        $changes = DB::table('entry_changes')->leftJoin('users', 'users.id', '=', 'entry_changes.user_id')->where('entry_id', $entry->id)
            ->where('entry_changes.version', '>=', $oldest)->where('entry_changes.version', '<=', $latest)->select('entry_changes.*', 'users.name as user')->get()->keyBy('version');
        $type = $this->types->find($entry->workspace_id ?? $this->access->entry($entry->id)->workspace_id, $entry->type);
        $stored = $this->secrets->versions('entries/'.$entry->id);
        $history = [];
        for ($v = $latest; $v >= $oldest; $v--) {
            $row = $changes[$v] ?? null;
            $meta = $stored[$v] ?? null;
            $details = $row ? json_decode($row->changes, true, flags: JSON_THROW_ON_ERROR) : null;
            if ($details !== null) {
                $details['fields'] = $this->named((array) ($details['fields'] ?? []), $type);
                if (isset($details['carried'])) {
                    $details['carried'] = array_values(array_intersect((array) $details['carried'], $type ? array_column($type->fields, 'key') : []));
                }
            }
            $history[] = [
                'version' => $v,
                'at' => $row?->created_at ?? ($meta['created_time'] ?? null),
                'user' => $row?->user,
                'event' => $row?->event,
                'changes' => $details,
                // Unknown backend state (null) is shown as available; a reveal still fails closed.
                'available' => $stored === null || ($meta !== null && empty($meta['destroyed']) && empty($meta['deletion_time'])),
            ];
        }

        return $history;
    }

    public function reveal(string $id, int $version = 0): array
    {
        return $this->revealVersion($id, $version)['payload'];
    }

    /**
     * The payload together with the exact version that was read, so callers never label it from a later SQL read.
     *
     * @return array{version: int, payload: array<string, string>}
     */
    public function revealVersion(string $id, int $version = 0): array
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        abort_unless($entry->state === 'active', 409);
        $this->access->audit($entry->workspace_id, 'entry.reveal', $id);
        if ($version < 0 || $version > $entry->version) {
            throw ValidationException::withMessages(['version' => 'Choose an existing version.']);
        }
        $version = $version ?: (int) $entry->version;

        return ['version' => $version, 'payload' => $this->secrets->read('entries/'.$id, $version)];
    }

    /**
     * Keys whose value in $payload differs from the current version; values are compared server-side only.
     * When $version (the version $payload was read from) is still the current one, nothing differs.
     */
    public function differsFromCurrent(string $id, #[\SensitiveParameter] array $payload, ?int $version = null): array
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        if ($version !== null && $version === (int) $entry->version) {
            return [];
        }
        $current = $this->secrets->read('entries/'.$id, $entry->version);
        $keys = array_unique([...array_keys($payload), ...array_keys($current)]);

        return array_values(array_filter($keys, fn ($k) => ! isset($payload[$k], $current[$k]) || ! hash_equals($payload[$k], $current[$k])));
    }

    /** Which of $keys the current version lacks or holds empty. Only key names leave this method, never values. */
    public function missingFields(string $id, array $keys): array
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        abort_if($entry->state === 'destroyed', 409);
        $payload = $this->secrets->read('entries/'.$id, (int) $entry->version);

        return array_values(array_filter($keys, fn ($key) => ($payload[$key] ?? '') === ''));
    }

    public function archive(string $id): void
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        DB::transaction(function () use ($id, $entry) {
            DB::table('entries')->where('id', $id)->update(['state' => 'archived', 'updated_at' => now()]);
            DB::table('grants')->where('entry_id', $id)->update(['revoked_at' => now()]);
            DB::table('operations')->where('entry_id', $id)->whereIn('state', ['pending', 'approved'])->update(['state' => 'revoked', 'updated_at' => now()]);
            $this->access->audit($entry->workspace_id, 'entry.archive', $id);
        });
    }

    public function restoreVersion(string $id, int $version): void
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        $payload = $this->reveal($id, $version);
        $this->update($id, ['project_id' => $entry->project_id, 'environment_id' => $entry->environment_id, 'name' => $entry->name, 'alias' => $entry->alias,
            'type' => $entry->type, 'tags' => $entry->tags, 'rotate_at' => $entry->rotate_at ? substr((string) $entry->rotate_at, 0, 10) : null,
            'secret' => json_encode($payload, JSON_THROW_ON_ERROR)], $version);
    }

    public function unarchive(string $id): void
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        abort_unless($entry->state === 'archived', 409);
        DB::transaction(function () use ($id, $entry) {
            DB::table('entries')->where('id', $id)->update(['state' => 'active', 'updated_at' => now()]);
            $this->access->audit($entry->workspace_id, 'entry.unarchive', $id);
        });
    }

    public function destroy(string $id): void
    {
        $entry = $this->access->entry($id, ['owner']);
        abort_unless($entry->state === 'archived', 409);
        DB::transaction(function () use ($id, $entry) {
            $this->access->audit($entry->workspace_id, 'entry.destroy', $id);
            $this->secrets->destroy('entries/'.$id);
            DB::table('entries')->where('id', $id)->update(['state' => 'destroyed', 'updated_at' => now()]);
        });
    }

    public function import(string $workspace, #[\SensitiveParameter] string $json): int
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        $rows = json_decode($json);
        $message = 'Paste a JSON array of 1 to 50 entry objects.';
        if (! is_array($rows) || count($rows) < 1 || count($rows) > 50) {
            throw ValidationException::withMessages(['import' => $message]);
        }
        foreach ($rows as $i => $row) {
            if (! $row instanceof \stdClass) {
                throw ValidationException::withMessages(['import' => 'Entry '.($i + 1).' is not an entry object. '.$message]);
            }
        }
        $rows = json_decode($json, true);
        foreach ($rows as $i => $row) {
            try {
                $data = $this->validate($row);
                $this->sameWorkspace($workspace, $data);
                $this->checkType($workspace, $data);
            } catch (ValidationException $e) {
                // The messages name fields and rules, never values; the entry is named by its place in the array.
                throw ValidationException::withMessages(['import' => array_map(fn ($text) => 'Entry '.($i + 1).': '.$text, array_slice(array_merge(...array_values($e->errors())), 0, 2))]);
            }
        }

        return DB::transaction(function () use ($rows, $workspace) {
            // Taken once for the whole batch, before any row is re-checked and written.
            $this->types->lock($workspace);
            foreach ($rows as $row) {
                $this->create($row);
            }

            return count($rows);
        });
    }
}
