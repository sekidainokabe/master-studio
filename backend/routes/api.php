<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangesetController;
use App\Http\Controllers\ChangesetRecordController;
use App\Http\Controllers\ChangesetValidationController;
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
    Route::patch('/changesets/{changeset}', [ChangesetController::class, 'update']);
    Route::delete('/changesets/{changeset}', [ChangesetController::class, 'destroy']);
    Route::get('/changesets/{changeset}/validation', ChangesetValidationController::class);

    // URL の中で2つ目以降のモデルを key で取り出すと、Laravel は「1つ目のモデルに属するもの」だけを探す（$changeset->masterTables()）。
    // マスタは変更セットに属さないため、この絞り込みを外す
    Route::get('/changesets/{changeset}/masters/{masterTable:key}/records', [ChangesetRecordController::class, 'index'])
        ->withoutScopedBindings();
    Route::put('/changesets/{changeset}/masters/{masterTable:key}/records', [ChangesetRecordController::class, 'update'])
        ->withoutScopedBindings();
    // 反対に変更（item）は変更セットに属するため、URL の変更セットのものだけを探す。他の変更セットの id なら 404
    Route::delete('/changesets/{changeset}/items/{item}', [ChangesetRecordController::class, 'destroyItem'])
        ->scopeBindings();
});
