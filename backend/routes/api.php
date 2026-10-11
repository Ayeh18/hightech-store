<?php

use App\Http\Controllers\Api\Account\AddressController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\CouponController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\FulfilmentController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\ReconciliationController;
use App\Http\Controllers\Api\Admin\RefundController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\UserController;
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
use App\Http\Controllers\Api\Orders\OrderRefundController;
use App\Http\Controllers\Api\Orders\PaymentController;
use App\Http\Controllers\Api\Payments\FakeGatewayController;
use App\Http\Controllers\Api\Payments\PaymentWebhookController;
use App\Http\Controllers\Api\PcBuilder\BuildController;
use App\Http\Controllers\Api\PcBuilder\PcBuilderController;
use App\Http\Controllers\Api\PcBuilder\RecommendationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (all prefixed with /api)
|--------------------------------------------------------------------------
| Authentication uses Sanctum's SPA cookie mode: the Vue app first calls
| GET /sanctum/csrf-cookie, then POSTs to these endpoints with the session cookie.
*/

// Rate limits: `throttle:attempts,minutes,name`. The NAME matters. Without it, Laravel counts all routes that use a
// numeric limit in ONE shared bucket per customer, so a few cart edits would use up the allowance for placing an order.
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1,register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1,login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Anything under /api/admin requires the "admin.access" permission (granted to admin and staff).
Route::middleware(['auth:sanctum', 'can:admin.access'])->prefix('admin')->group(function () {
    // Getting orders to customers (staff and admins).
    Route::middleware('can:orders.manage')->group(function () {
        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::get('orders/{order}', [AdminOrderController::class, 'show']);
        Route::post('orders/{order}/status', [FulfilmentController::class, 'advance'])->middleware('throttle:60,1,admin-order-status');
        Route::post('orders/{order}/shipment', [FulfilmentController::class, 'ship'])->middleware('throttle:60,1,admin-order-ship');
        Route::post('orders/{order}/shipment/delivered', [FulfilmentController::class, 'deliver'])->middleware('throttle:60,1,admin-order-deliver');
    });

    // Money going back to customers (admins only: it needs the refunds.manage permission).
    Route::middleware('can:refunds.manage')->group(function () {
        Route::get('refunds', [RefundController::class, 'index']);
        Route::get('payments/needing-refund', [RefundController::class, 'needingRefund']);
        Route::post('refunds/{refund}/approve', [RefundController::class, 'approve'])->middleware('throttle:30,1,admin-refund-approve');
        Route::post('refunds/{refund}/reject', [RefundController::class, 'reject'])->middleware('throttle:30,1,admin-refund-reject');
        Route::post('refunds/{refund}/retry', [RefundController::class, 'retry'])->middleware('throttle:30,1,admin-refund-retry');
        Route::post('payments/{payment}/refunds', [RefundController::class, 'issue'])->middleware('throttle:30,1,admin-refund-issue');
        Route::post('orders/{order}/cancel-and-refund', [RefundController::class, 'cancelOrder'])->middleware('throttle:30,1,admin-order-cancel');
    });

    // The dashboard and sales reports (staff and admins). Payment tables on the dashboard also need payments.view.
    Route::middleware('can:reports.view')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'show']);
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/sales/export', [ReportController::class, 'export'])->middleware('throttle:20,1,admin-report-export');
    });

    // Every payment and its diary. Declared AFTER payments/needing-refund above, so that fixed address is matched first.
    Route::middleware('can:payments.view')->group(function () {
        Route::get('payments', [AdminPaymentController::class, 'index']);
        Route::get('payments/{payment}', [AdminPaymentController::class, 'show']);
        Route::get('reconciliation', [ReconciliationController::class, 'index'])->middleware('throttle:20,1,admin-reconciliation');
        Route::post('payments/{payment}/sync', [ReconciliationController::class, 'sync'])->middleware('throttle:30,1,admin-payment-sync');
    });

    Route::middleware('can:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('throttle:30,1,admin-user-roles');
        Route::put('users/{user}/status', [UserController::class, 'updateStatus'])->middleware('throttle:30,1,admin-user-status');
    });

    Route::middleware('can:audit.view')->group(function () {
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/actions', [AuditLogController::class, 'actions']);
    });

    Route::middleware('can:coupons.manage')->group(function () {
        Route::get('coupons', [CouponController::class, 'index']);
        Route::post('coupons', [CouponController::class, 'store'])->middleware('throttle:30,1,admin-coupons');
        Route::put('coupons/{coupon}', [CouponController::class, 'update'])->middleware('throttle:30,1,admin-coupons');
        Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->middleware('throttle:30,1,admin-coupons');
    });
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
Route::post('pc-builder/validate', [PcBuilderController::class, 'validateBuild'])->middleware('throttle:60,1,builder-validate');
Route::get('pc-builder/parts', [PcBuilderController::class, 'parts'])->middleware('throttle:120,1,builder-parts');
// Suggesting a build may call an outside AI service with a small daily allowance, so it is limited more tightly.
Route::get('pc-builder/recommendations/options', [RecommendationController::class, 'options']);
Route::post('pc-builder/recommendations', [RecommendationController::class, 'store'])->middleware('throttle:10,1,builder-recommend');
Route::post('pc-builder/recommendations/{recommendation}/accept', [RecommendationController::class, 'accept'])->middleware('throttle:30,1,builder-recommend-accept');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('builds', [BuildController::class, 'index']);
    Route::post('builds', [BuildController::class, 'store'])->middleware('throttle:30,1,builds-save');
    Route::get('builds/{build}', [BuildController::class, 'show']);
    Route::put('builds/{build}', [BuildController::class, 'update']);
    Route::delete('builds/{build}', [BuildController::class, 'destroy']);
    Route::post('builds/{build}/duplicate', [BuildController::class, 'duplicate'])->middleware('throttle:30,1,builds-duplicate');
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
    Route::post('products', [CartProductController::class, 'store'])->middleware('throttle:60,1,cart-add-product');
    Route::post('builds', [CartBuildController::class, 'store'])->middleware('throttle:30,1,cart-add-build');
    Route::put('items/{item}', [CartItemController::class, 'update'])->middleware('throttle:60,1,cart-update-item');
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

    Route::post('checkout/preview', [CheckoutController::class, 'preview'])->middleware('throttle:60,1,checkout-preview');
    Route::post('checkout', [CheckoutController::class, 'place'])->middleware('throttle:20,1,checkout');

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:20,1,order-cancel');
    Route::post('orders/{order}/payments', [PaymentController::class, 'store'])->middleware('throttle:20,1,order-pay');
    Route::post('orders/{order}/refund-request', [OrderRefundController::class, 'store'])->middleware('throttle:10,1,order-refund-request');
});

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
| Webhooks are called by the payment company, not by a logged-in customer: no cookie, no login. The signature on each
| request is what proves it is genuine (see WebhookSigner). The fake-gateway routes are the pretend company's payment
| page and exist only while the fake gateway is enabled (never in production).
*/

Route::post('webhooks/{provider}', [PaymentWebhookController::class, 'handle'])->middleware('throttle:300,1,webhooks');

Route::prefix('fake-gateway')->middleware('throttle:60,1,fake-gateway')->group(function () {
    Route::get('payments/{reference}', [FakeGatewayController::class, 'show']);
    Route::post('payments/{reference}/complete', [FakeGatewayController::class, 'complete']);
});
