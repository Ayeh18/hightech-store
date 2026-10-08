<?php

namespace App\Http\Resources\PcBuilder;

use App\Models\PcBuild;
use App\Services\PcBuilder\BuildPricer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved build with live prices. Requires items.product.{brand, category.specDefinitions, images, inventory, specifications}.
 *
 * @mixin PcBuild
 */
class PcBuildResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $priced = app(BuildPricer::class)->priceBuild($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            ...(new PricedBuildResource($priced))->toArray($request),
        ];
    }
}
