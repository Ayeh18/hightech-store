<?php

namespace App\Http\Resources\PcBuilder;

use App\Http\Controllers\Api\PcBuilder\BuildShareController;
use App\Models\PcBuild;
use App\Services\PcBuilder\BuildEvaluator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved build with live prices and its compatibility verdict.
 * Requires items.product.{brand, category.specDefinitions, images, inventory, specifications}.
 *
 * @mixin PcBuild
 */
class PcBuildResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $evaluated = app(BuildEvaluator::class)->evaluateBuild($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // The public link, when the owner has switched one on (this shape is only ever sent to the owner).
            'share' => $this->isShared() ? BuildShareController::link($this->resource) : null,
            ...(new EvaluatedBuildResource($evaluated))->toArray($request),
        ];
    }
}
