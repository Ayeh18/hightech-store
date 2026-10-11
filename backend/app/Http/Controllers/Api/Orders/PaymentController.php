<?php

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Resources\Orders\PaymentResource;
use App\Models\Order;
use App\Services\Payments\PaymentService;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    /**
     * Start paying for an order.
     *
     * "Pay now": returns the payment to go and pay on (its `checkout_url`). The amount is the order's own total, decided
     * here, so the request has no body. 201 for a new payment, 200 when the one already waiting is handed out again.
     */
    #[ApiResponse(201, 'A new payment was started.', type: 'array{data: PaymentResource}')]
    #[ApiResponse(200, 'A payment was already waiting for this order: it is returned again.', type: 'array{data: PaymentResource}')]
    public function store(Request $request, Order $order, PaymentService $payments): JsonResponse
    {
        Gate::authorize('pay', $order);

        $payment = $payments->start($order, $request->user());

        return (new PaymentResource($payment))->response()
            ->setStatusCode($payment->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
