<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\QuotaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

    Route::prefix('documents')->group(function () {
        Route::get('/', fn () => response()->json(['data' => []])); // TODO 2.2 index
        Route::post('/', fn () => response()->json(['message' => 'Not implemented'], 501)); // TODO 2.1 store
    });
    Route::get('/quotas', [QuotaController::class, 'show']);

    // Example protected routes for demonstration
    Route::get('/profile', function (Request $request) {
        return response()->json([
            'message' => 'Profile data for '.$request->user()->name,
            'user' => $request->user(),
        ]);
    })->middleware('role:user,admin'); // Allow both user and admin roles

    Route::get('/admin/stats', function (Request $request) {
        return response()->json([
            'message' => 'Admin-only statistics',
            'data' => [
                'total_users' => 42,
                'active_sessions' => 15,
            ],
        ]);
    })->middleware('role:admin'); // Allow only admin role
});
