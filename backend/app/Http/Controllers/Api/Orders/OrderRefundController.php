<?php

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\RefundRequestRequest;
use App\Http\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class OrderRefundController extends Controller
{
    /**
     * Ask for a refund on a delivered order.
     *
     * "I want my money back". Nothing is paid out until staff review it. Returns the updated order.
     */
    public function store(RefundRequestRequest $request, Order $order, RefundService $refunds): JsonResponse
    {
        Gate::authorize('requestRefund', $order);

        $refunds->request($order, $request->user(), $request->validated('note'));

        return (new OrderResource(Order::findOrFail($order->id)->load(Order::DETAIL_RELATIONS)))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
