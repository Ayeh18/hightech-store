<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (all prefixed with /api)
|--------------------------------------------------------------------------
| Authentication uses Sanctum's SPA cookie mode: the Vue app first calls
| GET /sanctum/csrf-cookie, then POSTs to these endpoints with the session cookie.
*/

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Anything under /api/admin requires the "admin.access" permission (granted to admin and staff).
Route::middleware(['auth:sanctum', 'can:admin.access'])->prefix('admin')->group(function () {
    Route::get('ping', fn () => response()->json(['message' => 'pong']));
});
