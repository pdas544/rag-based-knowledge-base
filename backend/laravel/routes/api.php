<?php

use App\Http\Controllers\AdminDocumentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\QuotaController;
use Illuminate\Support\Facades\Route;

// Public probe for the load balancer / orchestrator.
Route::get('/health', HealthController::class);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Phase 1: Core Chat
    Route::get('/conversations/export-all', [ConversationController::class, 'exportAll']);
    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::patch('/conversations/{conversation}/title', [ConversationController::class, 'updateTitle']);
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware(['throttle:60,1', 'quota']);
    Route::get('/conversations/{conversation}/export', [ConversationController::class, 'export']);

    // Phase 2: RAG documents (staged global KB)
    Route::apiResource('documents', DocumentController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::get('/quotas', [QuotaController::class, 'show']);

    // Phase 2: admin review gate (role:admin middleware routes/api.php:22 pattern)
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/documents', [AdminDocumentController::class, 'index']);
        Route::get('/documents/stats', [AdminDocumentController::class, 'stats']);
        Route::post('/documents/{document}/approve', [AdminDocumentController::class, 'approve']);
        Route::post('/documents/{document}/reject', [AdminDocumentController::class, 'reject']);
    });

    // Real identity endpoint (also used by the SPA to refresh the user).
    Route::get('/profile', [AuthController::class, 'user'])->middleware('role:user,admin');
});
