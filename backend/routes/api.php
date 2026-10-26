<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangesetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MasterController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // {masterTable:key}：URL の key（abilities など）でマスタを取り出す。見つからなければ 404
    Route::get('/masters', [MasterController::class, 'index']);
    Route::get('/masters/{masterTable:key}/schema', [MasterController::class, 'schema']);
    Route::get('/masters/{masterTable:key}/records', [MasterController::class, 'records']);

    Route::get('/changesets', [ChangesetController::class, 'index']);
    Route::post('/changesets', [ChangesetController::class, 'store']);
    Route::get('/changesets/{changeset}', [ChangesetController::class, 'show']);
});
