<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cart\CartResource;
use App\Services\Cart\CartEvaluator;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart, private readonly CartEvaluator $evaluator) {}

    public function show(Request $request): CartResource
    {
        return $this->respond($request);
    }

    /** Remove everything from the cart. */
    public function clear(Request $request): CartResource
    {
        $this->cart->clear($request->user());

        return $this->respond($request);
    }

    /** "Accept the new prices": stop warning about price changes that happened before now. */
    public function acceptPrices(Request $request): CartResource
    {
        $this->cart->acceptPrices($request->user());

        return $this->respond($request);
    }

    private function respond(Request $request): CartResource
    {
        return new CartResource($this->evaluator->evaluate($this->cart->cartFor($request->user())));
    }
}
