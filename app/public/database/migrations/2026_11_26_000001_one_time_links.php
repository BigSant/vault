<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The workspace's Onetime Secret account. The API token itself lives in OpenBao (settings/<workspace>).
        Schema::create('link_settings', function (Blueprint $t) {
            $t->uuid('workspace_id')->primary();
            $t->string('region', 8);
            $t->string('email', 200);
            $t->unsignedInteger('default_lifetime');
            $t->unsignedInteger('max_lifetime');
            $t->timestamp('token_saved_at')->nullable();
            $t->timestamps();
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
        // An agent may create one-time links only when an owner allowed it.
        Schema::table('machines', function (Blueprint $t) {
            $t->boolean('links')->default(false);
        });
        // One row per one-time link. No content, link, receipt or passphrase here: those stay in OpenBao (links/<id>)
        // until the link has ended.
        Schema::create('links', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('workspace_id')->index();
            $t->uuid('entry_id')->nullable()->index();
            $t->unsignedInteger('version')->nullable();
            $t->uuid('operation_id')->nullable()->index();
            $t->uuid('machine_id')->nullable();
            $t->uuid('user_id')->nullable();
            $t->string('request_id', 80)->nullable();
            $t->string('note', 120)->nullable();
            $t->text('fields')->nullable();
            $t->unsignedInteger('lifetime');
            $t->boolean('passphrase')->default(false);
            $t->string('state', 20);
            // The account the link was made in: checks and burns go there, also after the workspace changed account.
            $t->string('region', 8)->nullable();
            $t->string('account', 200)->nullable();
            // HMAC of what an agent asked for: a retry with the same request_id must ask for the same thing.
            $t->string('digest', 64)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('checked_at')->nullable();
            // When what OpenBao held for the link was destroyed; maintenance retries until it is set.
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
            $t->unique(['machine_id', 'request_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a verified backup; destructive rollback is disabled.');
    }
};
