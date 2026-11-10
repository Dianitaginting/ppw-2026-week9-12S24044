<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\BukuApiController;
use App\Http\Controllers\Api\V1\PeminjamanApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthTokenController::class, 'login']);

    Route::get('/bukus', [BukuApiController::class, 'index']);
    Route::get('/bukus/{id}', [BukuApiController::class, 'show'])->whereNumber('id');
    Route::get('/bukus/{id}/referensi-eksternal', [BukuApiController::class, 'referensiEksternal'])->whereNumber('id');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthTokenController::class, 'me']);
        Route::post('/auth/logout', [AuthTokenController::class, 'logout']);

        Route::post('/bukus', [BukuApiController::class, 'store']);
        Route::put('/bukus/{id}', [BukuApiController::class, 'update'])->whereNumber('id');
        Route::patch('/bukus/{id}', [BukuApiController::class, 'update'])->whereNumber('id');
        Route::delete('/bukus/{id}', [BukuApiController::class, 'destroy'])->whereNumber('id');

        Route::get('/peminjamans', [PeminjamanApiController::class, 'index']);
        Route::post('/peminjamans', [PeminjamanApiController::class, 'store']);
        Route::patch('/peminjamans/{id}/kembalikan', [PeminjamanApiController::class, 'kembalikan'])->whereNumber('id');
    });
});
