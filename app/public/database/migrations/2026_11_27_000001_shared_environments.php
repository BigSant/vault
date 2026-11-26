<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Environments become one list per workspace (like entry types), no longer a copy per project. An entry names its
 * project and its environment separately. Existing environments of the same name (case and accents folded, as the
 * MySQL collation compares) merge into one; it is production when any of them was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $t) {
            $t->uuid('workspace_id')->nullable();
        });
        Schema::table('entries', function (Blueprint $t) {
            $t->uuid('project_id')->nullable();
        });

        $fold = static fn (string $name) => mb_convert_case(preg_replace('/\p{Mn}/u', '', \Normalizer::normalize(trim($name), \Normalizer::FORM_D)), MB_CASE_FOLD);
        $rows = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->select('environments.*', 'projects.workspace_id as project_workspace')->orderBy('projects.name')->orderBy('environments.name')->get();
        $kept = [];
        foreach ($rows as $env) {
            DB::table('entries')->where('environment_id', $env->id)->update(['project_id' => $env->project_id]);
            $key = $env->project_workspace.'|'.$fold($env->name);
            if (! isset($kept[$key])) {
                $kept[$key] = $env;
                DB::table('environments')->where('id', $env->id)->update(['workspace_id' => $env->project_workspace]);

                continue;
            }
            $keep = $kept[$key];
            DB::table('entries')->where('environment_id', $env->id)->update(['environment_id' => $keep->id]);
            if ($env->production && ! $keep->production) {
                DB::table('environments')->where('id', $keep->id)->update(['production' => true]);
                $keep->production = true;
            }
            DB::table('environments')->where('id', $env->id)->delete();
        }

        Schema::table('environments', function (Blueprint $t) {
            $t->dropForeign(['project_id']);
            $t->dropUnique(['project_id', 'name']);
        });
        Schema::table('environments', function (Blueprint $t) {
            $t->dropColumn('project_id');
        });
        Schema::table('environments', function (Blueprint $t) {
            $t->uuid('workspace_id')->nullable(false)->change();
            $t->unique(['workspace_id', 'name']);
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
        Schema::table('entries', function (Blueprint $t) {
            $t->uuid('project_id')->nullable(false)->change();
            $t->index('project_id');
            $t->foreign('project_id')->references('id')->on('projects');
        });
        // The per-project template is gone: the workspace's list is what every project uses.
        Schema::table('workspaces', function (Blueprint $t) {
            $t->dropColumn('environment_template');
        });
        // A workspace that had no environment yet starts with the usual list.
        $have = DB::table('environments')->distinct()->pluck('workspace_id')->all();
        foreach (DB::table('workspaces')->whereNotIn('id', $have)->pluck('id') as $workspace) {
            \App\Vault\Structure::seed($workspace);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a verified backup; destructive rollback is disabled.');
    }
};
