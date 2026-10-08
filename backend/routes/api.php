<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Catalog\BrandController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\InventoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Catalog\ProductImageController;
use App\Http\Controllers\Api\Catalog\SpecDefinitionController;
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

/*
|--------------------------------------------------------------------------
| Catalog
|--------------------------------------------------------------------------
| Reads are public. Writes need a permission (see RolePermissionSeeder); the permission
| middleware (can:...) rejects everyone else before the controller runs.
| `{product}` accepts the numeric id or the slug.
*/

Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/{category}', [CategoryController::class, 'show']);
Route::get('categories/{category}/filters', [CategoryController::class, 'filters']);
Route::get('brands', [BrandController::class, 'index']);
Route::get('products', [ProductController::class, 'index']);
Route::get('products/{product}', [ProductController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('can:products.manage')->group(function () {
        Route::get('admin/products', [ProductController::class, 'adminIndex']);
        Route::post('products', [ProductController::class, 'store']);
        Route::put('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);
        Route::post('products/{product}/images', [ProductImageController::class, 'store']);
        Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy']);
    });

    Route::post('products/{product}/inventory/adjustments', [InventoryController::class, 'adjust'])
        ->middleware('can:inventory.manage');

    Route::middleware('can:categories.manage')->group(function () {
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
        Route::post('categories/{category}/specs', [SpecDefinitionController::class, 'store']);
        Route::put('spec-definitions/{specDefinition}', [SpecDefinitionController::class, 'update']);
        Route::delete('spec-definitions/{specDefinition}', [SpecDefinitionController::class, 'destroy']);
    });

    Route::middleware('can:brands.manage')->group(function () {
        Route::post('brands', [BrandController::class, 'store']);
        Route::put('brands/{brand}', [BrandController::class, 'update']);
        Route::delete('brands/{brand}', [BrandController::class, 'destroy']);
    });
});
