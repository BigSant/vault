<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Field definitions only: values always stay in OpenBao.
        Schema::create('entry_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('workspace_id');
            $t->string('key', 40);
            $t->string('name', 80);
            $t->text('fields');
            $t->timestamps();
            $t->unique(['workspace_id', 'key']);
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
        Schema::table('workspaces', function (Blueprint $t) {
            // NULL means "not seeded yet"; built-in types are created on first use.
            $t->timestamp('types_initialized_at')->nullable();
            // NULL means the default local/test/stage/prod template.
            $t->text('environment_template')->nullable();
        });
        Schema::table('environments', function (Blueprint $t) {
            $t->boolean('production')->default(false);
        });
        DB::table('environments')->where('name', 'prod')->update(['production' => true]);
        // Who changed what and when. Field names and non-secret metadata only, never values.
        Schema::create('entry_changes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('entry_id')->index();
            $t->unsignedInteger('version');
            $t->string('event', 20);
            $t->uuid('user_id')->nullable();
            $t->text('changes');
            $t->timestamp('created_at');
            $t->unique(['entry_id', 'version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a verified backup; destructive rollback is disabled.');
    }
};
