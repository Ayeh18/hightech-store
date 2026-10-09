<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdvanceOrderRequest;
use App\Http\Requests\Admin\ShipOrderRequest;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\Order;
use App\Services\Orders\FulfilmentService;
use Illuminate\Http\Request;

/** Getting a paid order to the customer. The route requires the orders.manage permission. */
class FulfilmentController extends Controller
{
    public function __construct(private readonly FulfilmentService $fulfilment) {}

    /** PAID -> PROCESSING, or PROCESSING -> PACKED. */
    public function advance(AdvanceOrderRequest $request, Order $order): AdminOrderResource
    {
        $this->fulfilment->advance($order, OrderStatus::from($request->validated('status')), $request->user());

        return $this->fresh($order);
    }

    /** PACKED -> SHIPPED, recording the carrier and tracking number. */
    public function ship(ShipOrderRequest $request, Order $order): AdminOrderResource
    {
        $this->fulfilment->ship($order, $request->validated('carrier'), $request->validated('tracking_number'), $request->user());

        return $this->fresh($order);
    }

    /** SHIPPED -> DELIVERED. */
    public function deliver(Request $request, Order $order): AdminOrderResource
    {
        $this->fulfilment->markDelivered($order, $request->user());

        return $this->fresh($order);
    }

    private function fresh(Order $order): AdminOrderResource
    {
        return new AdminOrderResource(Order::findOrFail($order->id)->load([...Order::DETAIL_RELATIONS, 'user']));
    }
}
