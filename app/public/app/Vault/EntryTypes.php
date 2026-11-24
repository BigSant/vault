<?php

namespace App\Vault;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Workspace-defined entry types. Only field definitions live here; values stay in OpenBao. */
final class EntryTypes
{
    public const KINDS = ['text' => 'Text', 'secret' => 'Secret (hidden while typing)', 'multiline' => 'Multi-line text'];

    public const MAX_FIELDS = 20;

    public const KEY = '/^[a-z][a-z0-9_]{0,39}$/D';

    /** Seeded into every new workspace; afterwards they are ordinary, fully editable types. */
    private const DEFAULTS = [
        'login' => ['Login', [['username', 'Username', 'text', true], ['password', 'Password', 'secret', true]]],
        'api_key' => ['API key', [['token', 'API key', 'secret', true]]],
        'database' => ['Database', [['username', 'Username', 'text', true], ['password', 'Password', 'secret', true]]],
        'dynamic_database' => ['Short-lived DB access', [['role', 'Operator-registered DB role', 'text', true]]],
        'ssh' => ['SSH key', [['private_key', 'Private SSH key', 'multiline', true], ['passphrase', 'SSH key passphrase', 'secret', false]]],
        'secure_note' => ['Secure note', [['note', 'Secure note', 'multiline', true]]],
    ];

    public function __construct(private Access $access) {}

    /** Fields a registered action reads from the secret. */
    public static function actionFields(string $kind): array
    {
        return match ($kind) {
            'browser' => ['username', 'password'],
            'database' => ['role'],
            default => ['token'],
        };
    }

    public function all(string $workspace): Collection
    {
        $this->ensure($workspace);

        return DB::table('entry_types')->where('workspace_id', $workspace)->orderBy('name')->get()->map(fn ($t) => $this->decode($t));
    }

    public function find(string $workspace, string $key): ?object
    {
        $this->ensure($workspace);
        $row = DB::table('entry_types')->where('workspace_id', $workspace)->where('key', $key)->first();

        return $row ? $this->decode($row) : null;
    }

    /** key => display name, for lists and filters. */
    public function names(string $workspace): array
    {
        return $this->all($workspace)->pluck('name', 'key')->all();
    }

    /** key => number of entries that still exist (destroyed entries keep a row but no longer use the type). */
    public function usage(string $workspace): array
    {
        $usage = [];
        foreach ($this->entries($workspace)->groupBy('entries.type')->selectRaw('entries.type as type, COUNT(*) as total')->get() as $row) {
            // Type keys are lowercase; fold legacy rows that differ only by case, as a case-insensitive collation would.
            $key = strtolower((string) $row->type);
            $usage[$key] = ($usage[$key] ?? 0) + (int) $row->total;
        }

        return $usage;
    }

    /**
     * Entry creation holds this shared lock while it checks the type and inserts the entry; save() and delete()
     * take the same row exclusively, so a type cannot disappear between the check and the insert.
     * Seeding runs first so the exclusive seeding lock is never requested while a shared lock is held.
     */
    public function lock(string $workspace): void
    {
        $this->ensure($workspace);
        DB::table('workspaces')->where('id', $workspace)->sharedLock()->first();
    }

    /**
     * Create (key = null) or edit a type. Existing field keys are immutable so stored values keep their meaning;
     * removing a field from the type keeps old values readable in every version.
     * An edit that posts `revision` is rejected with 409 when the type changed since that revision was read.
     */
    public function save(string $workspace, ?string $key, array $input): string
    {
        $name = $input['name'] ?? '';
        if (! is_string($name) || ($name = trim($name)) === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'A type name is required (up to 80 characters).']);
        }
        $revision = $input['revision'] ?? null;
        if ($revision !== null && ! is_string($revision)) {
            throw ValidationException::withMessages(['revision' => 'Invalid type revision.']);
        }

        return DB::transaction(function () use ($workspace, $key, $name, $revision, $input) {
            $this->ensure($workspace);
            DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            if ($key === null) {
                $fields = $this->fields($input, []);
                $key = $this->uniqueKey($workspace, $name);
                $id = (string) Str::uuid();
                DB::table('entry_types')->insert(['id' => $id, 'workspace_id' => $workspace, 'key' => $key, 'name' => $name,
                    'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $row = DB::table('entry_types')->where('workspace_id', $workspace)->where('key', $key)->first();
                abort_unless($row, 404);
                // Forms that do not post a revision yet are accepted (last write wins) until every form sends it.
                abort_if($revision !== null && $revision !== '' && ! hash_equals(self::revision($row->name, $row->fields), $revision), 409,
                    'This type was changed by someone else. Reload the page and apply your changes again.');
                $fields = $this->fields($input, array_column(json_decode($row->fields, true, flags: JSON_THROW_ON_ERROR), 'key'));
                $id = $row->id;
                DB::table('entry_types')->where('id', $id)->update(['name' => $name, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            $this->access->audit($workspace, 'type.save', $id);

            return $key;
        });
    }

    public function delete(string $workspace, string $key): void
    {
        DB::transaction(function () use ($workspace, $key) {
            DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            $row = DB::table('entry_types')->where('workspace_id', $workspace)->where('key', $key)->first();
            abort_unless($row, 404);
            if ($this->entries($workspace)->whereRaw('LOWER(entries.type) = ?', [$key])->exists()) {
                throw ValidationException::withMessages(['type' => 'This type is used by entries (including archived ones), so it cannot be removed.']);
            }
            DB::table('entry_types')->where('id', $row->id)->delete();
            $this->access->audit($workspace, 'type.delete', $row->id);
        });
    }

    /** Entries of the workspace that still exist. */
    private function entries(string $workspace): Builder
    {
        return DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('projects.workspace_id', $workspace)->where('entries.state', '!=', 'destroyed');
    }

    /** Changes whenever the name or field list changes; posted back by the edit form to detect concurrent edits. */
    private static function revision(string $name, string $fields): string
    {
        return substr(hash('sha256', $name."\n".$fields), 0, 32);
    }

    /** One parallel form array; anything but a list of plain strings is a validation error. */
    private function column(array $input, string $name): array
    {
        $values = $input[$name] ?? [];
        if (! is_array($values)) {
            throw ValidationException::withMessages(['fields' => 'Invalid field data.']);
        }
        foreach ($values as $value) {
            if ($value !== null && ! is_string($value)) {
                throw ValidationException::withMessages(['fields' => 'Invalid field data.']);
            }
        }

        return array_map(fn ($value) => trim((string) $value), $values);
    }

    /**
     * Parallel form arrays → validated field list. Rows without a label are ignored.
     * A posted key must be one of $stored (the type's current keys); rows without a key are new and get a
     * server-generated key that never collides with a current key, including one being removed.
     */
    private function fields(array $input, array $stored): array
    {
        $keys = $this->column($input, 'field_key');
        $labels = $this->column($input, 'field_label');
        $kinds = $this->column($input, 'field_kind');
        $required = $this->column($input, 'field_required');
        $remove = $this->column($input, 'field_remove');
        $seen = [];
        foreach ($keys as $key) {
            if ($key === '') {
                continue;
            }
            if (! in_array($key, $stored, true)) {
                throw ValidationException::withMessages(['fields' => 'Field keys are assigned by the server and cannot be set or changed.']);
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['fields' => 'Each existing field may appear only once.']);
            }
            $seen[$key] = true;
        }
        $taken = array_fill_keys($stored, true);
        $fields = [];
        foreach ($labels as $i => $label) {
            $key = $keys[$i] ?? '';
            if ($label === '' || ($key !== '' && in_array($key, $remove, true))) {
                continue;
            }
            if (mb_strlen($label) > 80) {
                throw ValidationException::withMessages(['fields' => 'A field name is too long (up to 80 characters).']);
            }
            if ($key === '') {
                $base = $key = $this->slug($label, 'field');
                for ($n = 2; isset($taken[$key]); $n++) {
                    $key = substr($base, 0, 36).'_'.$n;
                }
                $taken[$key] = true;
            }
            $kind = $kinds[$i] ?? 'text';
            if (! preg_match(self::KEY, $key) || ! isset(self::KINDS[$kind])) {
                throw ValidationException::withMessages(['fields' => 'Invalid field key or kind.']);
            }
            $fields[] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'required' => ($required[$i] ?? '0') === '1'];
        }
        if ($fields === [] || count($fields) > self::MAX_FIELDS) {
            throw ValidationException::withMessages(['fields' => 'A type must have between 1 and '.self::MAX_FIELDS.' fields.']);
        }

        return $fields;
    }

    private function uniqueKey(string $workspace, string $name): string
    {
        $base = substr($this->slug($name, 'type'), 0, 36);
        $key = $base;
        for ($n = 2; DB::table('entry_types')->where('workspace_id', $workspace)->where('key', $key)->exists(); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }

    private function slug(string $label, string $prefix): string
    {
        $slug = substr(Str::slug($label, '_'), 0, 40);

        return preg_match(self::KEY, $slug) ? $slug : substr($prefix.'_'.$slug, 0, 40);
    }

    private function ensure(string $workspace): void
    {
        if (DB::table('workspaces')->where('id', $workspace)->whereNull('types_initialized_at')->doesntExist()) {
            return;
        }
        DB::transaction(function () use ($workspace) {
            $row = DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            if (! $row || $row->types_initialized_at !== null) {
                return;
            }
            foreach (self::DEFAULTS as $key => [$name, $fields]) {
                if (DB::table('entry_types')->where('workspace_id', $workspace)->where('key', $key)->exists()) {
                    continue;
                }
                $fields = array_map(fn ($f) => ['key' => $f[0], 'label' => $f[1], 'kind' => $f[2], 'required' => $f[3]], $fields);
                DB::table('entry_types')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'key' => $key, 'name' => $name,
                    'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('workspaces')->where('id', $workspace)->update(['types_initialized_at' => now()]);
        });
    }

    private function decode(object $row): object
    {
        $row->revision = self::revision($row->name, $row->fields);
        $row->fields = json_decode($row->fields, true, flags: JSON_THROW_ON_ERROR);

        return $row;
    }
}
