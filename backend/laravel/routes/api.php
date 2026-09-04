<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Example protected routes for demonstration
    Route::get('/profile', function (Request $request) {
        return response()->json([
            'message' => 'Profile data for ' . $request->user()->name,
            'user' => $request->user()
        ]);
    })->middleware('role:user,admin'); // Allow both user and admin roles
    
    Route::get('/admin/stats', function (Request $request) {
        return response()->json([
            'message' => 'Admin-only statistics',
            'data' => [
                'total_users' => 42,
                'active_sessions' => 15
            ]
        ]);
    })->middleware('role:admin'); // Allow only admin role
});