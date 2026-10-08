<?php

namespace App\Http\Resources\PcBuilder;

use App\Models\PcBuild;
use App\Services\PcBuilder\BuildPricer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact shape for "My builds". Requires items.product.{category, inventory} to be eager loaded.
 *
 * @mixin PcBuild
 */
class PcBuildSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $priced = app(BuildPricer::class)->priceBuild($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'subtotal' => $priced->subtotal,
            'item_count' => $this->items->sum('quantity'),
            'parts' => $this->items->map(fn ($item) => ['slot' => $item->slot->value, 'name' => $item->product->name])->values(),
            'complete' => $priced->isComplete(),
            'purchasable' => $priced->isPurchasable(),
            'issue_count' => count($priced->issues),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
