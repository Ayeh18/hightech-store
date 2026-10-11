<?php

namespace App\Http\Controllers\Api\PcBuilder;

use App\Enums\BuilderSlot;
use App\Http\Controllers\Controller;
use App\Http\Requests\PcBuilder\BuildSelectionRequest;
use App\Http\Requests\PcBuilder\PartsRequest;
use App\Http\Resources\PcBuilder\CandidatePartResource;
use App\Http\Resources\PcBuilder\EvaluatedBuildResource;
use App\Models\Category;
use App\Services\PcBuilder\BuildEvaluator;
use App\Services\PcBuilder\PartSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PcBuilderController extends Controller
{
    /**
     * The builder's slots.
     *
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

    /**
     * Check a build: prices, stock and compatibility.
     *
     * Recalculates a build from current database prices and stock and checks its compatibility.
     * The browser's totals are never trusted.
     */
    public function validateBuild(BuildSelectionRequest $request, BuildEvaluator $evaluator): EvaluatedBuildResource
    {
        return new EvaluatedBuildResource($evaluator->evaluateSelection($request->selection()));
    }

    /**
     * Parts for one slot, best fit first.
     *
     * Parts for one slot, ranked by how well they fit what is already chosen: compatible first, then warnings,
     * then incompatible ones (hidden unless include_incompatible=1). Search, filters and sorting still apply.
     */
    public function parts(PartsRequest $request, PartSuggester $suggester): AnonymousResourceCollection
    {
        $suggestions = $suggester->suggest(
            $request->slot(),
            $request->filters(),
            $request->selectedParts(),
            $request->boolean('include_incompatible'),
            max(1, $request->integer('page', 1)),
        );

        return CandidatePartResource::collection($suggestions->page)->additional([
            'compatibility' => ['hidden_incompatible' => $suggestions->hiddenIncompatible, 'counts' => $suggestions->counts],
        ]);
    }
}
