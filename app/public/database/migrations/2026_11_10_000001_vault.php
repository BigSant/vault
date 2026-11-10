<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::create('settings',function(Blueprint $t){$t->string('key')->primary();$t->text('value');});
        DB::table('settings')->insert(['key'=>'bootstrap_used','value'=>'0']);
        Schema::create('users',function(Blueprint $t){$t->uuid('id')->primary();$t->string('name');$t->string('email')->unique();$t->string('password');$t->boolean('mfa_enabled')->default(false);$t->bigInteger('totp_counter')->default(-1);$t->rememberToken();$t->timestamps();});
        Schema::create('sessions',function(Blueprint $t){$t->string('id')->primary();$t->uuid('user_id')->nullable()->index();$t->string('ip_address',45)->nullable();$t->text('user_agent')->nullable();$t->longText('payload');$t->integer('last_activity')->index();});
        Schema::create('cache',function(Blueprint $t){$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
        Schema::create('cache_locks',function(Blueprint $t){$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
        Schema::create('workspaces',function(Blueprint $t){$t->uuid('id')->primary();$t->string('name');$t->timestamps();});
        Schema::create('members',function(Blueprint $t){$t->uuid('workspace_id');$t->uuid('user_id');$t->string('role');$t->primary(['workspace_id','user_id']);$t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();$t->foreign('user_id')->references('id')->on('users');});
        Schema::create('projects',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('workspace_id');$t->string('name');$t->unique(['workspace_id','name']);$t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();});
        Schema::create('environments',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('project_id');$t->string('name');$t->unique(['project_id','name']);$t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();});
        Schema::create('entries',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('environment_id');$t->string('name');$t->string('alias');$t->string('type');$t->string('tags')->default('');$t->unsignedInteger('version');$t->string('state')->default('active');$t->timestamp('rotate_at')->nullable();$t->timestamps();$t->foreign('environment_id')->references('id')->on('environments');});
        Schema::create('machines',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('workspace_id');$t->string('name');$t->char('token_hash',64)->unique();$t->timestamp('expires_at');$t->timestamp('revoked_at')->nullable();$t->timestamps();$t->foreign('workspace_id')->references('id')->on('workspaces');});
        Schema::create('grants',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('machine_id');$t->uuid('entry_id');$t->string('action');$t->boolean('approval')->default(true);$t->timestamp('expires_at');$t->timestamp('revoked_at')->nullable();$t->timestamps();$t->foreign('machine_id')->references('id')->on('machines');$t->foreign('entry_id')->references('id')->on('entries');$t->unique(['machine_id','entry_id','action']);});
        Schema::create('operations',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('machine_id');$t->uuid('grant_id');$t->uuid('entry_id');$t->string('action');$t->unsignedInteger('version');$t->string('state');$t->string('request_id',80);$t->uuid('approved_by')->nullable();$t->timestamp('expires_at');$t->text('result')->nullable();$t->timestamps();$t->unique(['machine_id','request_id']);});
        Schema::create('audit',function(Blueprint $t){$t->bigIncrements('id');$t->uuid('workspace_id')->nullable()->index();$t->string('actor');$t->string('event');$t->uuid('subject')->nullable();$t->string('outcome');$t->timestamp('created_at');});
    }
    public function down(): void { throw new RuntimeException('Restore a verified backup; destructive rollback is disabled.'); }
};
