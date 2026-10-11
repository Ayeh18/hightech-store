<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Http\Resources\Admin\AdminOrderSummaryResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Staff view of every customer's orders. The route requires the orders.manage permission. */
class OrderController extends Controller
{
    /** List every order (staff view). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $term = isset($filters['q']) ? '%'.addcslashes(trim($filters['q']), '%_\\').'%' : null;

        $orders = Order::query()
            ->with('user')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['payment_status'] ?? null, fn ($q, $status) => $q->where('payment_status', $status))
            ->when($term, fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'ilike', $term)
                ->orWhereHas('user', fn ($u) => $u->where('email', 'ilike', $term)->orWhere('name', 'ilike', $term))))
            ->latest()
            ->latest('id') // orders placed in the same second still come out in a stable order
            ->paginate(20);

        return AdminOrderSummaryResource::collection($orders);
    }

    /** Show one order, with its customer and every payment. */
    public function show(Order $order): AdminOrderResource
    {
        return new AdminOrderResource($order->load([...Order::DETAIL_RELATIONS, 'user']));
    }
}
