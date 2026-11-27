<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only an executor identity (a trusted host process, never an LLM agent) may hold a grant of a handoff kind
        // (storage_state). Set when the identity is made; never changed afterwards.
        Schema::table('machines', function (Blueprint $t) {
            $t->boolean('executor')->default(false);
        });
        // A handoff operation keeps running while its file exists. handoff: null (no file yet), written, removed.
        // No path and no value here: the executor derives the path from the fixed directory and the operation ID.
        Schema::table('operations', function (Blueprint $t) {
            $t->string('handoff', 10)->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->index(['entry_id', 'state']);
            $t->index(['machine_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a verified backup; destructive rollback is disabled.');
    }
};
