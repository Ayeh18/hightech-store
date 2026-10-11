<?php

namespace App\Http\Resources\PcBuilder;

use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Services\PcBuilder\BuildEvaluator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A build as a stranger with its link sees it: its name and its parts, priced and checked now. Deliberately absent: the
 * build's id, who owns it, and the name of any part that is no longer on sale (a visitor is treated like any other
 * guest, who is never told about products the shop has withdrawn).
 *
 * @mixin PcBuild
 */
class SharedBuildResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $selection = $this->items
            ->map(fn (PcBuildItem $item) => ['slot' => $item->slot->value, 'product_id' => $item->product_id, 'quantity' => $item->quantity])
            ->all();

        return [
            'name' => $this->name,
            'shared_at' => $this->shared_at?->toIso8601String(),
            ...(new EvaluatedBuildResource(app(BuildEvaluator::class)->evaluateSelection($selection)))->toArray($request),
        ];
    }
}
