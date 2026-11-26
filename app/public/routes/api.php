<?php

use App\Http\AgentApi;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:api-ip', 'vault.machine', 'throttle:machine'])->group(function () {
    Route::get('/v1/catalog', [AgentApi::class, 'catalog']);
    Route::post('/v1/execute', [AgentApi::class, 'execute']);
    Route::get('/v1/operations/{id}', [AgentApi::class, 'status'])->whereUuid('id');
    Route::post('/v1/operations/{id}/cancel', [AgentApi::class, 'cancel'])->whereUuid('id');
    Route::get('/v1/links/options', [AgentApi::class, 'linkOptions']);
    Route::post('/v1/links', [AgentApi::class, 'linkCreate']);
    Route::get('/v1/links/{id}', [AgentApi::class, 'linkStatus'])->whereUuid('id');
    Route::post('/v1/links/{id}/burn', [AgentApi::class, 'linkBurn'])->whereUuid('id');
    Route::post('/mcp', [AgentApi::class, 'mcp']);
});
