<?php

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\CheckoutPreviewRequest;
use App\Http\Requests\Orders\PlaceOrderRequest;
use App\Http\Resources\Orders\CheckoutQuoteResource;
use App\Http\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Orders\CheckoutService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /**
     * Price the order before placing it.
     *
     * What the order would cost with this address and coupon. Nothing is reserved or saved.
     */
    public function preview(CheckoutPreviewRequest $request): CheckoutQuoteResource
    {
        return new CheckoutQuoteResource($this->checkout->quote(
            $request->user(),
            $request->integer('address_id') ?: null,
            $request->input('coupon_code'),
        ));
    }

    /**
     * Place the order.
     *
     * Turns the cart into an order and holds its stock. Send an `Idempotency-Key` header so that a retry returns the
     * order already created instead of making a second one.
     */
    #[HeaderParameter('Idempotency-Key', description: 'Any text of 8 to 64 letters, digits, `-` or `_` that is new for each order, for example a random id made when the checkout page opens.', type: 'string', example: 'checkout-3f9c2a1e7b')]
    #[ApiResponse(201, 'A new order was placed.', type: 'array{data: OrderResource}')]
    #[ApiResponse(200, 'This Idempotency-Key was already used: the order it created is returned again.', type: 'array{data: OrderResource}')]
    public function place(PlaceOrderRequest $request): JsonResponse
    {
        $placed = $this->checkout->place(
            $request->user(),
            $request->integer('address_id'),
            $request->input('coupon_code'),
            $request->input('notes'),
            $request->validated('idempotency_key'),
        );

        return (new OrderResource($placed->order->load(Order::DETAIL_RELATIONS)))
            ->response()
            ->setStatusCode($placed->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
