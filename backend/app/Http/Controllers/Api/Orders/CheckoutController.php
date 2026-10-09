<?php

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\CheckoutPreviewRequest;
use App\Http\Requests\Orders\PlaceOrderRequest;
use App\Http\Resources\Orders\CheckoutQuoteResource;
use App\Http\Resources\Orders\OrderResource;
use App\Services\Orders\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /** What the order would cost with this address and coupon. Nothing is reserved or saved. */
    public function preview(CheckoutPreviewRequest $request): CheckoutQuoteResource
    {
        return new CheckoutQuoteResource($this->checkout->quote(
            $request->user(),
            $request->integer('address_id') ?: null,
            $request->input('coupon_code'),
        ));
    }

    /** Places the order. 201 for a new order, 200 when the same Idempotency-Key was already processed. */
    public function place(PlaceOrderRequest $request): JsonResponse
    {
        $placed = $this->checkout->place(
            $request->user(),
            $request->integer('address_id'),
            $request->input('coupon_code'),
            $request->input('notes'),
            $request->input('idempotency_key'),
        );

        return (new OrderResource($placed->order->load('items')))
            ->response()
            ->setStatusCode($placed->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
