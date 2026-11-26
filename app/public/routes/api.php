<?php

use App\Http\AgentApi;
use App\Vault\Operations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:api-ip', 'vault.machine', 'throttle:machine'])->group(function () {
    Route::get('/v1/catalog', [AgentApi::class, 'catalog']);
    Route::post('/v1/execute', [AgentApi::class, 'execute']);
    Route::get('/v1/operations/{id}', [AgentApi::class, 'status'])->whereUuid('id');
    Route::post('/v1/operations/{id}/cancel', [AgentApi::class, 'cancel'])->whereUuid('id');
    Route::post('/v1/operations/{id}/release', [AgentApi::class, 'release'])->whereUuid('id');
    Route::get('/v1/links/options', [AgentApi::class, 'linkOptions']);
    Route::post('/v1/links', [AgentApi::class, 'linkCreate']);
    Route::get('/v1/links/{id}', [AgentApi::class, 'linkStatus'])->whereUuid('id');
    Route::post('/v1/links/{id}/burn', [AgentApi::class, 'linkBurn'])->whereUuid('id');
    Route::post('/mcp', [AgentApi::class, 'mcp']);
});
// The broker's report on a handoff file (written, removed). Authenticated by the broker key, like the GET in web.php.
Route::post('/internal/operations/{id}/handoff', function (Request $r, string $id, Operations $ops) {
    abort_unless(hash_equals(trim(file_get_contents(config('vault.broker_key'))), (string) $r->bearerToken()), 401);
    abort_unless($r->isJson(), 415);
    $body = $r->json()->all();
    abort_if(array_diff(array_keys($body), ['event', 'lease_expires_at', 'reason']), 422);

    return $ops->handoffEvent($id, $body);
})->whereUuid('id');
