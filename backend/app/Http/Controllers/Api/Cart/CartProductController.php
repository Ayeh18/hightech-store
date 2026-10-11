<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddProductToCartRequest;
use App\Http\Resources\Cart\CartResource;
use App\Services\Cart\CartEvaluator;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CartProductController extends Controller
{
    /**
     * Add a product to my cart.
     *
     * Add a single product (or more of one already in the cart). Returns the whole updated cart.
     */
    public function store(AddProductToCartRequest $request, CartService $cart, CartEvaluator $evaluator): JsonResponse
    {
        $cart->addProduct($request->user(), (int) $request->validated('product_id'), $request->quantity());

        return (new CartResource($evaluator->evaluate($cart->cartFor($request->user()))))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
