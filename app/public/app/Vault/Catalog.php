<?php

namespace App\Vault;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class Catalog
{
    public function __construct(private Secrets $secrets, private Access $access) {}

    public function validate(array $input): array
    {
        if (! isset($input['secret']) && isset($input['secret_fields']) && is_array($input['secret_fields'])) {
            $input['secret'] = json_encode(array_filter($input['secret_fields'], fn ($v) => is_string($v) && $v !== ''), JSON_THROW_ON_ERROR);
        }
        $data = Validator::make($input, [
            'environment_id' => 'required|uuid', 'name' => 'required|string|max:160', 'alias' => 'required|string|max:100|regex:/^[a-zA-Z0-9_. -]+$/',
            'type' => 'required|in:login,api_key,database,dynamic_database,ssh,secure_note', 'tags' => 'nullable|string|max:200',
            'secret' => 'required|string|max:65536', 'rotate_at' => 'nullable|date_format:Y-m-d',
        ])->validate();
        try {
            $payload = json_decode($data['secret'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422);
        }
        abort_unless(is_array($payload) && ! array_is_list($payload) && count($payload) <= 20, 422);
        foreach ($payload as $key => $value) {
            abort_unless(is_string($key) && preg_match('/^[a-z_]{1,40}$/D', $key) && is_string($value) && strlen($value) <= 16000, 422);
        }
        $required = ['login' => ['username', 'password'], 'api_key' => ['token'], 'database' => ['username', 'password'], 'dynamic_database' => ['role'], 'ssh' => ['private_key'], 'secure_note' => ['note']];
        foreach ($required[$data['type']] as $field) {
            abort_unless(isset($payload[$field]) && $payload[$field] !== '', 422);
        }
        $data['payload'] = $payload;
        unset($data['secret']);

        return $data;
    }

    public function create(array $input): string
    {
        $data = $this->validate($input);
        $scope = $this->access->environment($data['environment_id']);

        return DB::transaction(function () use ($data, $scope) {
            $id = (string) Str::uuid();
            // Write audit intent first. A failed DB transaction can leave only an unreachable backend orphan.
            $this->access->audit($scope->workspace_id, 'entry.create', $id);
            $version = $this->secrets->write('entries/'.$id, $data['payload'], 0);
            unset($data['payload']);
            DB::table('entries')->insert([...$data, 'tags' => $data['tags'] ?? '', 'rotate_at' => $data['rotate_at'] ?? null,
                'id' => $id, 'version' => $version, 'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    public function update(string $id, array $input): void
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        DB::transaction(function () use ($entry, $id, $input) {
            $current = DB::table('entries')->where('id', $id)->lockForUpdate()->first();
            if (! isset($input['secret'])) {
                $fields = $input['secret_fields'] ?? [];
                abort_unless(is_array($fields), 422);
                foreach ($fields as $value) {
                    abort_unless(is_string($value) || $value === null, 422);
                }
                $payload = $this->secrets->read('entries/'.$id, $current->version);
                $payload = array_replace($payload, array_filter($fields, fn ($value) => is_string($value) && $value !== ''));
                $clear = $input['clear_fields'] ?? [];
                abort_unless(is_array($clear) && ! array_diff($clear, ['passphrase']), 422);
                foreach ($clear as $field) {
                    unset($payload[$field]);
                }
                $input['secret'] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            $data = $this->validate($input);
            // No moving, type changes or archive resurrection through a stale edit form.
            abort_unless($data['environment_id'] === $current->environment_id && $data['type'] === $current->type && $current->state === 'active', 422);
            $this->access->audit($entry->workspace_id, 'entry.rotate', $id);
            $version = $this->secrets->write('entries/'.$id, $data['payload'], $current->version);
            unset($data['payload']);
            DB::table('entries')->where('id', $id)->update([...$data, 'tags' => $data['tags'] ?? '', 'rotate_at' => $data['rotate_at'] ?? null, 'version' => $version, 'updated_at' => now()]);
            DB::table('operations')->where('entry_id', $id)->whereIn('state', ['pending', 'approved'])->update(['state' => 'revoked', 'updated_at' => now()]);
        });
    }

    public function reveal(string $id, int $version = 0): array
    {
        $entry = $this->access->entry($id, ['owner', 'editor']);
        abort_unless($entry->state === 'active', 409);
        $this->access->audit($entry->workspace_id, 'entry.reveal', $id);
        abort_if($version < 0 || $version > $entry->version, 422);

        return $this->secrets->read('entries/'.$id, $version ?: $entry->version);
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
        $this->update($id, ['environment_id' => $entry->environment_id, 'name' => $entry->name, 'alias' => $entry->alias,
            'type' => $entry->type, 'tags' => $entry->tags, 'secret' => json_encode($payload, JSON_THROW_ON_ERROR)]);
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
        $this->access->workspace($workspace, ['owner']);
        try {
            $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422);
        }
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) > 0 && count($rows) <= 50, 422);
        foreach ($rows as $row) {
            abort_unless(is_array($row), 422);
            $data = $this->validate($row);
            abort_unless($this->access->environment($data['environment_id'])->workspace_id === $workspace, 404);
        }

        return DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->create($row);
            }

            return count($rows);
        });
    }
}
