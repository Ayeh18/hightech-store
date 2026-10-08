<?php

namespace App\Http\Controllers\Api\PcBuilder;

use App\Enums\BuilderSlot;
use App\Http\Controllers\Controller;
use App\Http\Requests\PcBuilder\BuildSelectionRequest;
use App\Http\Resources\PcBuilder\PricedBuildResource;
use App\Models\Category;
use App\Services\PcBuilder\BuildPricer;
use Illuminate\Http\JsonResponse;

class PcBuilderController extends Controller
{
    /**
     * The builder's slots, in screen order, and the category that supplies each one.
     * Products for a slot come from GET /api/products?category={category.slug}.
     */
    public function components(): JsonResponse
    {
        $categories = Category::active()->whereNotNull('builder_slot')->get()->keyBy(fn (Category $c) => $c->builder_slot->value);

        $slots = collect(BuilderSlot::cases())
            ->filter(fn (BuilderSlot $slot) => $categories->has($slot->value))
            ->map(fn (BuilderSlot $slot) => [
                'slot' => $slot->value,
                'label' => $slot->label(),
                'required' => $slot->isRequired(),
                'max_quantity' => $slot->maxQuantity(),
                'category' => ['slug' => $categories[$slot->value]->slug, 'name' => $categories[$slot->value]->name],
            ])
            ->values();

        return response()->json(['data' => $slots]);
    }

    /** Recalculates a build from current database prices and stock. The browser's totals are never trusted. */
    public function validateBuild(BuildSelectionRequest $request, BuildPricer $pricer): PricedBuildResource
    {
        return new PricedBuildResource($pricer->priceSelection($request->selection()));
    }
}
