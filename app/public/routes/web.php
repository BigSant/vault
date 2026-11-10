<?php

use App\Http\Identity;
use App\Http\Workspace;
use App\Vault\Operations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/login', [Identity::class, 'loginForm'])->name('login');
Route::post('/login', [Identity::class, 'login'])->middleware('throttle:login');
Route::post('/setup', [Identity::class, 'setup'])->middleware('throttle:login');
Route::get('/mfa', [Identity::class, 'mfaForm'])->middleware('throttle:mfa');
Route::post('/mfa', [Identity::class, 'mfa'])->middleware('throttle:mfa');
Route::get('/health.php', function () {
    try {
        DB::select('SELECT 1');
        $health = Http::timeout(2)->get(config('vault.bao_url').'/v1/sys/health');
        abort_unless($health->status() === 200, 503);

        return ['status' => 'ready', 'stage' => 'application'];
    } catch (Throwable) {
        return response()->json(['status' => 'unavailable', 'stage' => 'application'], 503);
    }
});
Route::get('/internal/operations/{id}', function (Request $r, string $id, Operations $ops) {
    abort_unless(hash_equals(trim(file_get_contents(config('vault.broker_key'))), (string) $r->bearerToken()), 401);
    $op = $ops->authorized($id);

    return $op ? ['allowed' => true, 'entry_id' => $op->entry_id, 'version' => (int) $op->version, 'action' => $op->action] : ['allowed' => false];
})->whereUuid('id');
Route::middleware('vault.auth')->group(function () {
    Route::get('/', [Workspace::class, 'index']);
    Route::post('/password', [Identity::class, 'changePassword'])->middleware('throttle:sensitive');
    Route::post('/logout', [Identity::class, 'logout']);
    Route::post('/confirm', [Identity::class, 'confirm'])->middleware('throttle:sensitive');
    Route::post('/workspaces', [Workspace::class, 'workspace']);
    Route::post('/workspaces/{workspace}/projects', [Workspace::class, 'project']);
    Route::post('/workspaces/{workspace}/environments', [Workspace::class, 'environment']);
    Route::get('/entries/new', [Workspace::class, 'entryForm']);
    Route::post('/entries', [Workspace::class, 'create']);
    Route::get('/entries/{id}', [Workspace::class, 'detail'])->whereUuid('id');
    Route::post('/entries/{id}', [Workspace::class, 'update'])->whereUuid('id');
    Route::post('/entries/{id}/reveal', [Workspace::class, 'reveal'])->whereUuid('id');
    Route::post('/entries/{id}/restore-version', [Workspace::class, 'restoreVersion'])->whereUuid('id');
    Route::post('/entries/{id}/unarchive', [Workspace::class, 'unarchive'])->whereUuid('id');
    Route::post('/entries/{id}/destroy', [Workspace::class, 'destroy'])->whereUuid('id');
    Route::post('/entries/{id}/archive', [Workspace::class, 'archive'])->whereUuid('id');
    Route::post('/workspaces/{workspace}/import', [Workspace::class, 'import']);
    Route::get('/workspaces/{workspace}/settings', [Workspace::class, 'settings']);
    Route::get('/workspaces/{workspace}/audit', [Workspace::class, 'audit']);
    Route::post('/workspaces/{workspace}/members', [Workspace::class, 'member']);
    Route::post('/workspaces/{workspace}/machines', [Workspace::class, 'machine']);
    Route::post('/workspaces/{workspace}/grants', [Workspace::class, 'grant']);
    Route::post('/workspaces/{workspace}/revoke/{kind}/{id}', [Workspace::class, 'revoke']);
    Route::post('/workspaces/{workspace}/operations/{id}/decision', [Workspace::class, 'decision']);
    Route::post('/workspaces/{workspace}/operations/{id}/mfa',[Workspace::class, 'mfa'])->middleware('throttle:sensitive');
});
