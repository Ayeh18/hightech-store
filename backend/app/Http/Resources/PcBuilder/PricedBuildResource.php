<?php

namespace App\Http\Resources\PcBuilder;

use App\Enums\BuilderSlot;
use App\Http\Resources\Catalog\ProductListResource;
use App\Services\PcBuilder\BuildIssue;
use App\Services\PcBuilder\PricedBuild;
use App\Services\PcBuilder\PricedLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The server-calculated view of a build. Every amount is recalculated from current database prices.
 *
 * @mixin PricedBuild
 */
class PricedBuildResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'items' => array_map(fn (PricedLine $line) => [
                'slot' => $line->slot->value,
                'slot_label' => $line->slot->label(),
                'quantity' => $line->quantity,
                'product' => $line->product ? new ProductListResource($line->product) : null,
                'unit_price' => $line->unitPrice,
                'line_total' => $line->lineTotal,
                'purchasable' => $line->purchasable,
            ], $this->lines),
            'subtotal' => $this->subtotal,
            'item_count' => array_sum(array_map(fn (PricedLine $l) => $l->quantity, $this->lines)),
            'complete' => $this->isComplete(),
            'missing_slots' => array_map(fn (BuilderSlot $s) => ['slot' => $s->value, 'label' => $s->label()], $this->missingSlots),
            'issues' => array_map(fn (BuildIssue $i) => [
                'code' => $i->code,
                'severity' => $i->severity,
                'message' => $i->message,
                'slot' => $i->slot?->value,
            ], $this->issues),
            'purchasable' => $this->isPurchasable(),
        ];
    }
}
