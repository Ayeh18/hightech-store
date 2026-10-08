<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\Cart\CartResource;
use App\Models\CartItem;
use App\Services\Cart\CartEvaluator;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CartItemController extends Controller
{
    public function __construct(private readonly CartService $cart, private readonly CartEvaluator $evaluator) {}

    public function update(UpdateCartItemRequest $request, CartItem $item): CartResource
    {
        Gate::authorize('update', $item);

        $this->cart->updateQuantity($item, $request->integer('quantity'));

        return $this->respond($request);
    }

    public function destroy(Request $request, CartItem $item): CartResource
    {
        Gate::authorize('delete', $item);

        $this->cart->remove($item);

        return $this->respond($request);
    }

    private function respond(Request $request): CartResource
    {
        return new CartResource($this->evaluator->evaluate($this->cart->cartFor($request->user())));
    }
}
