<?php

namespace App\Http\Resources\PcBuilder;

use App\Enums\BuilderSlot;
use App\Models\PcBuild;
use App\Services\Compatibility\ActiveRules;
use App\Services\PcBuilder\BuildEvaluator;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact shape for "My builds". Requires items.product.{category, inventory, specifications}.
 * A list controller can put the rule settings in the request attribute "compatibility_rules" so
 * they are loaded once for the whole list instead of once per build.
 *
 * @mixin PcBuild
 */
class PcBuildSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $evaluator = app(BuildEvaluator::class);
        $rules = $request->attributes->get('compatibility_rules');
        $evaluated = $evaluator->evaluateBuild($this->resource, $rules instanceof ActiveRules ? $rules : null);
        $priced = $evaluated->priced;

        return [
            'id' => $this->id,
            'name' => $this->name,
            /** @var Money */
            'subtotal' => $priced->subtotal,
            /** @var int */
            'item_count' => $this->items->sum('quantity'),
            // In builder order (CPU, motherboard, RAM, ...), not the order the rows were inserted.
            'parts' => $this->items
                ->sortBy(fn ($item) => array_search($item->slot, BuilderSlot::cases(), true))
                ->map(fn ($item) => ['slot' => $item->slot->value, 'name' => $item->product->name])
                ->values(),
            /** @var bool */
            'complete' => $priced->isComplete(),
            /** @var bool */
            'purchasable' => $evaluated->isPurchasable(),
            /** @var 'compatible'|'warning'|'incompatible' */
            'compatibility_status' => $evaluated->compatibility->status(),
            'issue_count' => count($priced->issues) + count($evaluated->compatibility->issues),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
