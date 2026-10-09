<?php

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Resources\Orders\OrderResource;
use App\Http\Resources\Orders\OrderSummaryResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    /** My orders, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $request->user()->orders()->with('items')->latest()->paginate(10);

        return OrderSummaryResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order->load(['items', 'payments']));
    }

    /** Cancel an unpaid order: its stock goes back on the shelf and any coupon is freed. */
    public function cancel(Request $request, Order $order, OrderService $orders): OrderResource
    {
        Gate::authorize('cancel', $order);

        return new OrderResource($orders->cancel($order, $request->user(), 'Cancelled by customer')->load(['items', 'payments']));
    }
}
