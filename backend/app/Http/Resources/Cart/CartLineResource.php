<?php

namespace App\Http\Resources\Cart;

use App\Http\Resources\Catalog\ProductListResource;
use App\Services\Cart\CartLine;
use App\Services\Cart\CartLinePart;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\PcBuilder\BuildIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CartLine */
class CartLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->item;
        $isBuild = $item->isBuild();

        return [
            'id' => $item->id,
            'kind' => $item->kind->value,
            'name' => $isBuild ? $item->name : $this->parts[0]->product->name,
            'quantity' => $item->quantity,
            'unit_price' => $this->unitPrice,
            'line_total' => $this->lineTotal,
            'source_build_id' => $item->pc_build_id,
            'product' => $isBuild ? null : new ProductListResource($this->parts[0]->product),
            'components' => $isBuild ? array_map(fn (CartLinePart $part) => [
                'slot' => $part->slot->value,
                'slot_label' => $part->slot->label(),
                'quantity' => $part->quantity,
                'product' => new ProductListResource($part->product),
                'on_sale' => $part->onSale,
                'unit_price' => $part->unitPrice,
                'price_when_added' => $part->priceWhenAdded,
            ], $this->parts) : [],
            'compatibility' => $this->compatibility ? [
                'status' => $this->compatibility->status(),
                'issues' => array_map(fn (CompatibilityIssue $i) => [
                    'rule' => $i->rule,
                    'severity' => $i->severity,
                    'message' => $i->message,
                    'slots' => array_map(fn ($s) => $s->value, $i->slots),
                ], $this->compatibility->issues),
            ] : null,
            'issues' => array_map(fn (BuildIssue $i) => [
                'code' => $i->code,
                'severity' => $i->severity,
                'message' => $i->message,
                'slot' => $i->slot?->value,
            ], $this->issues),
            'purchasable' => $this->isPriced() && ! $this->hasErrors(),
        ];
    }
}
