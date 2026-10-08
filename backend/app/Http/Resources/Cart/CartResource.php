<?php

namespace App\Http\Resources\Cart;

use App\Services\Cart\CartLine;
use App\Services\Cart\EvaluatedCart;
use App\Services\PcBuilder\BuildIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cart with every price, stock level and compatibility verdict recalculated from the database.
 *
 * @mixin EvaluatedCart
 */
class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'items' => array_map(fn (CartLine $line) => (new CartLineResource($line))->toArray($request), $this->lines),
            'subtotal' => $this->subtotal,
            'item_count' => $this->itemCount,
            'checkout_ready' => $this->isCheckoutReady(),
            'has_price_changes' => collect($this->lines)
                ->contains(fn (CartLine $line) => collect($line->issues)->contains(fn (BuildIssue $i) => $i->code === 'price_changed')),
        ];
    }
}
