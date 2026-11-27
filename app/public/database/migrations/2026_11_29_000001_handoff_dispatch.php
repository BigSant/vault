<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the broker confirmed the start. Until then a broker that does not know the operation may simply not
        // have registered it yet (a concurrent retry); after it, an unknown operation was lost in a restart.
        Schema::table('operations', function (Blueprint $t) {
            $t->timestamp('dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a verified backup; destructive rollback is disabled.');
    }
};
