<?php
use Illuminate\Support\Facades\Route;
use App\Http\AgentApi;
Route::middleware(['throttle:120,1','vault.machine','throttle:machine'])->group(function(){
    Route::get('/v1/catalog',[AgentApi::class,'catalog']);
    Route::post('/v1/execute',[AgentApi::class,'execute']);
    Route::get('/v1/operations/{id}',[AgentApi::class,'status'])->whereUuid('id');
    Route::post('/v1/operations/{id}/cancel',[AgentApi::class,'cancel'])->whereUuid('id');
    Route::post('/mcp',[AgentApi::class,'mcp']);
});
