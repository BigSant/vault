<?php

namespace App\Vault;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Projects, environments and the per-workspace template used for new projects. */
final class Structure
{
    public const DEFAULT_TEMPLATE = [['name' => 'local', 'production' => false], ['name' => 'test', 'production' => false],
        ['name' => 'stage', 'production' => false], ['name' => 'prod', 'production' => true]];

    private const PROJECT_NAME_TAKEN = 'A project with this name already exists.';
    private const ENVIRONMENT_NAME_TAKEN = 'An environment with this name already exists in this project.';

    public function __construct(private Access $access) {}

    public function template(object $workspace): array
    {
        return $workspace->environment_template ? json_decode($workspace->environment_template, true, flags: JSON_THROW_ON_ERROR) : self::DEFAULT_TEMPLATE;
    }

    public function saveTemplate(string $workspace, array $names, array $production): void
    {
        $template = [];
        foreach ($names as $i => $name) {
            $name = $this->name($name, 'template', allowEmpty: true);
            if ($name === '') {
                continue;
            }
            $template[] = ['name' => $name, 'production' => ($production[$i] ?? '0') === '1'];
        }
        if (count($template) > 30) {
            throw ValidationException::withMessages(['template' => 'A template can have up to 30 environments.']);
        }
        if ($this->hasDuplicateNames(array_column($template, 'name'))) {
            throw ValidationException::withMessages(['template' => 'Environment names in the template must be unique.']);
        }
        DB::transaction(function () use ($workspace, $template) {
            DB::table('workspaces')->where('id', $workspace)->update(['environment_template' => json_encode($template, JSON_THROW_ON_ERROR)]);
            $this->access->audit($workspace, 'environment.template', $workspace);
        });
    }

    private function hasDuplicateNames(array $names): bool
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // Match the unique index on environments(project_id, name).
            $row = DB::selectOne("SELECT COUNT(DISTINCT name) AS total FROM JSON_TABLE(?, '$[*]'
                COLUMNS (name VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$')) AS names",
                [json_encode($names, JSON_THROW_ON_ERROR)]);

            return (int) $row->total !== count($names);
        }

        // SQLite has no built-in accent-insensitive collation.
        $folded = array_map(static fn (string $name) => mb_convert_case(
            preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($name, \Normalizer::FORM_D)), MB_CASE_FOLD
        ), $names);

        return count(array_unique($folded)) !== count($names);
    }

    public function createProject(object $workspace, string $name): string
    {
        $name = $this->name($name, 'name', 100);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($workspace, $name, $id) {
            $this->writeName(fn () => DB::table('projects')->insert([
                'id' => $id, 'workspace_id' => $workspace->id, 'name' => $name,
            ]), self::PROJECT_NAME_TAKEN);
            foreach ($this->template($workspace) as $env) {
                $this->writeName(fn () => DB::table('environments')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $id, 'name' => $env['name'], 'production' => $env['production'],
                ]), 'Edit the environment template to remove duplicate names.', 'template');
            }
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
            $this->project($workspace, $project);
            // Lock the environments before checking their entries to serialize concurrent inserts.
            DB::table('environments')->where('project_id', $project)->lockForUpdate()->chunkById(500, function ($environments) {
                $this->clearDestroyedEntries($environments->pluck('id')->all(), 'project',
                    'Remove all entries, including archived entries, before removing this project.');
            });
            DB::table('projects')->where('id', $project)->delete();
            $this->access->audit($workspace, 'project.delete', $project);
        });
    }

    public function addEnvironment(string $workspace, string $project, string $name, bool $production): void
    {
        $name = $this->name($name, 'name');
        DB::transaction(function () use ($workspace, $project, $name, $production) {
            $this->project($workspace, $project);
            $id = (string) Str::uuid();
            $this->writeName(fn () => DB::table('environments')->insert([
                'id' => $id, 'project_id' => $project, 'name' => $name, 'production' => $production,
            ]), self::ENVIRONMENT_NAME_TAKEN);
            $this->access->audit($workspace, 'environment.create', $id);
        });
    }

    public function updateEnvironment(string $workspace, string $environment, string $name, bool $production): void
    {
        $name = $this->name($name, 'name');
        DB::transaction(function () use ($workspace, $environment, $name, $production) {
            $this->environment($workspace, $environment);
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
            $this->clearDestroyedEntries([$environment], 'environment',
                'Remove all entries, including archived entries, before removing this environment.');
            DB::table('environments')->where('id', $environment)->delete();
            $this->access->audit($workspace, 'environment.delete', $environment);
        });
    }

    private function clearDestroyedEntries(array $environments, string $field, string $message): void
    {
        DB::table('entries')->whereIn('environment_id', $environments)->select('id', 'state')
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
        $row = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('environments.id', $environment)->where('projects.workspace_id', $workspace)->select('environments.*')->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
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
