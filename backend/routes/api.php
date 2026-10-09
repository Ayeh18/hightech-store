<?php

use App\Http\Controllers\Api\Account\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Cart\CartBuildController;
use App\Http\Controllers\Api\Cart\CartController;
use App\Http\Controllers\Api\Cart\CartItemController;
use App\Http\Controllers\Api\Cart\CartProductController;
use App\Http\Controllers\Api\Catalog\BrandController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\InventoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Catalog\ProductImageController;
use App\Http\Controllers\Api\Catalog\SpecDefinitionController;
use App\Http\Controllers\Api\Compatibility\CompatibilityRuleController;
use App\Http\Controllers\Api\Orders\CheckoutController;
use App\Http\Controllers\Api\Orders\OrderController;
use App\Http\Controllers\Api\Orders\PaymentController;
use App\Http\Controllers\Api\Payments\FakeGatewayController;
use App\Http\Controllers\Api\Payments\PaymentWebhookController;
use App\Http\Controllers\Api\PcBuilder\BuildController;
use App\Http\Controllers\Api\PcBuilder\PcBuilderController;
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

/*
|--------------------------------------------------------------------------
| PC builder
|--------------------------------------------------------------------------
| Anyone can build and price a PC (guests too). Saving builds needs an account, and a saved
| build is private to its owner.
*/

Route::get('pc-builder/components', [PcBuilderController::class, 'components']);
Route::post('pc-builder/validate', [PcBuilderController::class, 'validateBuild'])->middleware('throttle:60,1');
Route::get('pc-builder/parts', [PcBuilderController::class, 'parts'])->middleware('throttle:120,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('builds', [BuildController::class, 'index']);
    Route::post('builds', [BuildController::class, 'store'])->middleware('throttle:30,1');
    Route::get('builds/{build}', [BuildController::class, 'show']);
    Route::put('builds/{build}', [BuildController::class, 'update']);
    Route::delete('builds/{build}', [BuildController::class, 'destroy']);
    Route::post('builds/{build}/duplicate', [BuildController::class, 'duplicate'])->middleware('throttle:30,1');
});

// Compatibility rule settings (switch rules on/off, change severity, tune numbers).
Route::middleware(['auth:sanctum', 'can:compatibility.manage'])->group(function () {
    Route::get('compatibility-rules', [CompatibilityRuleController::class, 'index']);
    Route::put('compatibility-rules/{key}', [CompatibilityRuleController::class, 'update']);
});

/*
|--------------------------------------------------------------------------
| Cart
|--------------------------------------------------------------------------
| Customers only. Every response is the whole cart, re-priced and re-validated from the database.
| A cart line belongs to one customer; anyone else gets a 404.
*/

Route::middleware('auth:sanctum')->prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::delete('/', [CartController::class, 'clear']);
    Route::post('accept-prices', [CartController::class, 'acceptPrices']);
    Route::post('products', [CartProductController::class, 'store'])->middleware('throttle:60,1');
    Route::post('builds', [CartBuildController::class, 'store'])->middleware('throttle:30,1');
    Route::put('items/{item}', [CartItemController::class, 'update'])->middleware('throttle:60,1');
    Route::delete('items/{item}', [CartItemController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Addresses, checkout and orders
|--------------------------------------------------------------------------
| Customers only. Checkout accepts choices (address, coupon code, note) and never a price: totals are calculated by
| the server. Send an `Idempotency-Key` header with POST /checkout so a retry cannot create a second order.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('addresses', [AddressController::class, 'index']);
    Route::post('addresses', [AddressController::class, 'store']);
    Route::put('addresses/{address}', [AddressController::class, 'update']);
    Route::delete('addresses/{address}', [AddressController::class, 'destroy']);

    Route::post('checkout/preview', [CheckoutController::class, 'preview'])->middleware('throttle:60,1');
    Route::post('checkout', [CheckoutController::class, 'place'])->middleware('throttle:20,1');

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:20,1');
    Route::post('orders/{order}/payments', [PaymentController::class, 'store'])->middleware('throttle:20,1');
});

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
| Webhooks are called by the payment company, not by a logged-in customer: no cookie, no login. The signature on each
| request is what proves it is genuine (see WebhookSigner). The fake-gateway routes are the pretend company's payment
| page and exist only while the fake gateway is enabled (never in production).
*/

Route::post('webhooks/{provider}', [PaymentWebhookController::class, 'handle'])->middleware('throttle:300,1');

Route::prefix('fake-gateway')->middleware('throttle:60,1')->group(function () {
    Route::get('payments/{reference}', [FakeGatewayController::class, 'show']);
    Route::post('payments/{reference}/complete', [FakeGatewayController::class, 'complete']);
});
