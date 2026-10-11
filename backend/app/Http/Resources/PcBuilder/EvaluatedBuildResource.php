<?php

namespace App\Http\Resources\PcBuilder;

use App\Enums\BuilderSlot;
use App\Http\Resources\Catalog\ProductListResource;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\PcBuilder\BuildIssue;
use App\Services\PcBuilder\EvaluatedBuild;
use App\Services\PcBuilder\PricedLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The server's verdict on a build: current prices and stock plus compatibility.
 * Every number is recalculated from the database; nothing from the browser is trusted.
 *
 * @mixin EvaluatedBuild
 */
class EvaluatedBuildResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $priced = $this->priced;

        return [
            'items' => array_map(fn (PricedLine $line) => [
                'slot' => $line->slot->value,
                'slot_label' => $line->slot->label(),
                'quantity' => $line->quantity,
                'product' => $line->product ? new ProductListResource($line->product) : null,
                'unit_price' => $line->unitPrice,
                'line_total' => $line->lineTotal,
                'purchasable' => $line->purchasable,
            ], $priced->lines),
            'subtotal' => $priced->subtotal,
            'item_count' => array_sum(array_map(fn (PricedLine $l) => $l->quantity, $priced->lines)),
            'complete' => $priced->isComplete(),
            'missing_slots' => array_map(fn (BuilderSlot $s) => ['slot' => $s->value, 'label' => $s->label()], $priced->missingSlots),
            // Availability problems (stock, withdrawn products).
            'issues' => array_map(fn (BuildIssue $i) => [
                'code' => $i->code,
                'severity' => $i->severity,
                'message' => $i->message,
                'slot' => $i->slot?->value,
            ], $priced->issues),
            // Compatibility problems (sockets, sizes, power...).
            'compatibility' => [
                /** @var 'compatible'|'warning'|'incompatible' */
                'status' => $this->compatibility->status(),
                'issues' => array_map(fn (CompatibilityIssue $i) => [
                    'rule' => $i->rule,
                    'severity' => $i->severity,
                    'message' => $i->message,
                    'slots' => array_map(fn (BuilderSlot $s) => $s->value, $i->slots),
                ], $this->compatibility->issues),
                'metrics' => $this->compatibility->metrics,
            ],
            'purchasable' => $this->isPurchasable(),
        ];
    }
}
