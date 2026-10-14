<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SchemaArtifactController;
use App\Http\Controllers\Api\V1\SchemaImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('login', [AuthController::class, 'login']);
        });

        Route::middleware('throttle:5,1')->group(function () {
            Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
            Route::post('reset-password', [AuthController::class, 'resetPassword']);
        });

        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')
            ->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('confirm-password', [AuthController::class, 'confirmPassword']);

            Route::post('email/verification-notification', [AuthController::class, 'sendVerification'])
                ->middleware('throttle:3,1');
        });
    });

    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::apiResource('projects', ProjectController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        Route::prefix('projects/{project}')->group(function () {
            Route::post('schema-imports', [SchemaImportController::class, 'store']);
            Route::get('schema-imports', [SchemaImportController::class, 'index']);
            Route::get('schema-imports/{import}', [SchemaImportController::class, 'show']);
            Route::post('schema-imports/{import}/retry', [SchemaImportController::class, 'retry']);

            Route::get('schema-artifacts', [SchemaArtifactController::class, 'index']);
            Route::get('schema-artifacts/{artifact}', [SchemaArtifactController::class, 'show']);
        });
    });
});
