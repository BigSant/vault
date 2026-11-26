<?php

namespace App\Vault;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Projects, and the workspace's environments: one shared list, like entry types. An entry names a project and an
 * environment of its workspace; no project has environments of its own.
 */
final class Structure
{
    /** The list a new workspace starts with. */
    public const DEFAULT_ENVIRONMENTS = [['name' => 'local', 'production' => false], ['name' => 'test', 'production' => false],
        ['name' => 'stage', 'production' => false], ['name' => 'prod', 'production' => true]];

    private const PROJECT_NAME_TAKEN = 'A project with this name already exists.';
    private const ENVIRONMENT_NAME_TAKEN = 'An environment with this name already exists.';

    public function __construct(private Access $access) {}

    /** Gives a workspace the default environments (a new workspace; the migration for one that had none). */
    public static function seed(string $workspace): void
    {
        foreach (self::DEFAULT_ENVIRONMENTS as $env) {
            DB::table('environments')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'name' => $env['name'], 'production' => $env['production']]);
        }
    }

    /** The workspace's environments: production first, then by name. */
    public static function environments(string $workspace)
    {
        return DB::table('environments')->where('workspace_id', $workspace)->orderByDesc('production')->orderBy('name')->get();
    }

    public function createProject(object $workspace, string $name): string
    {
        $name = $this->name($name, 'name', 100);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($workspace, $name, $id) {
            $this->writeName(fn () => DB::table('projects')->insert([
                'id' => $id, 'workspace_id' => $workspace->id, 'name' => $name,
            ]), self::PROJECT_NAME_TAKEN);
            $this->access->audit($workspace->id, 'project.create', $id);
        });

        return $id;
    }

    public function renameProject(string $workspace, string $project, string $name): void
    {
        $name = $this->name($name, 'name', 100);
        DB::transaction(function () use ($workspace, $project, $name) {
            $this->project($workspace, $project);
            $this->writeName(fn () => DB::table('projects')->where('id', $project)->update(['name' => $name]), self::PROJECT_NAME_TAKEN);
            $this->access->audit($workspace, 'project.rename', $project);
        });
    }

    public function deleteProject(string $workspace, string $project): void
    {
        DB::transaction(function () use ($workspace, $project) {
            // The project row is locked first: a new entry checks its project under the same lock.
            $this->project($workspace, $project);
            $this->clearDestroyedEntries('project_id', $project, 'project', 'Archive and destroy all entries in this project before removing it.');
            DB::table('projects')->where('id', $project)->delete();
            $this->access->audit($workspace, 'project.delete', $project);
        });
    }

    public function addEnvironment(string $workspace, string $name, bool $production): void
    {
        $name = $this->name($name, 'name');
        DB::transaction(function () use ($workspace, $name, $production) {
            $this->uniqueEnvironment($workspace, $name);
            $id = (string) Str::uuid();
            $this->writeName(fn () => DB::table('environments')->insert([
                'id' => $id, 'workspace_id' => $workspace, 'name' => $name, 'production' => $production,
            ]), self::ENVIRONMENT_NAME_TAKEN);
            $this->access->audit($workspace, 'environment.create', $id);
        });
    }

    public function updateEnvironment(string $workspace, string $environment, string $name, bool $production): void
    {
        $name = $this->name($name, 'name');
        DB::transaction(function () use ($workspace, $environment, $name, $production) {
            $this->environment($workspace, $environment);
            $this->uniqueEnvironment($workspace, $name, $environment);
            $this->writeName(fn () => DB::table('environments')->where('id', $environment)->update([
                'name' => $name, 'production' => $production,
            ]), self::ENVIRONMENT_NAME_TAKEN);
            $this->access->audit($workspace, 'environment.update', $environment);
        });
    }

    public function deleteEnvironment(string $workspace, string $environment): void
    {
        DB::transaction(function () use ($workspace, $environment) {
            $this->environment($workspace, $environment);
            $this->clearDestroyedEntries('environment_id', $environment, 'environment',
                'Archive and destroy all entries in this environment before removing it.');
            DB::table('environments')->where('id', $environment)->delete();
            $this->access->audit($workspace, 'environment.delete', $environment);
        });
    }

    private function clearDestroyedEntries(string $column, string $id, string $field, string $message): void
    {
        DB::table('entries')->where($column, $id)->select('id', 'state')
            ->lockForUpdate()->chunkById(500, function ($entries) use ($field, $message) {
                if ($entries->contains(fn ($entry) => $entry->state !== 'destroyed')) {
                    throw ValidationException::withMessages([$field => $message]);
                }
                $ids = $entries->pluck('id');
                DB::table('grants')->whereIn('entry_id', $ids)->delete();
                DB::table('entry_changes')->whereIn('entry_id', $ids)->delete();
                DB::table('entries')->whereIn('id', $ids)->delete();
                // Keep audit subjects and operation request IDs for history and idempotency.
            });
    }

    private function project(string $workspace, string $project): object
    {
        $row = DB::table('projects')->where('id', $project)->where('workspace_id', $workspace)->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
    }

    private function environment(string $workspace, string $environment): object
    {
        $row = DB::table('environments')->where('id', $environment)->where('workspace_id', $workspace)->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
    }

    /**
     * Environment names are unique in a workspace whatever their case or accents (MySQL's collation compares them so;
     * the check here gives the same answer on any database, under the workspace's environment rows' lock).
     */
    private function uniqueEnvironment(string $workspace, string $name, ?string $except = null): void
    {
        $fold = static fn (string $text) => mb_convert_case(preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($text, \Normalizer::FORM_D)), MB_CASE_FOLD);
        $taken = DB::table('environments')->where('workspace_id', $workspace)->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->lockForUpdate()->pluck('name')->contains(fn ($other) => $fold($other) === $fold($name));
        if ($taken) {
            throw ValidationException::withMessages(['name' => self::ENVIRONMENT_NAME_TAKEN]);
        }
    }

    private function writeName(Closure $write, string $message, string $field = 'name'): void
    {
        try {
            $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function name(mixed $value, string $field, int $max = 60, bool $allowEmpty = false): string
    {
        $value = trim(is_string($value) ? $value : '');
        if (($value === '' && ! $allowEmpty) || mb_strlen($value) > $max) {
            throw ValidationException::withMessages([$field => 'Enter a name of up to '.$max.' characters.']);
        }

        return $value;
    }
}
