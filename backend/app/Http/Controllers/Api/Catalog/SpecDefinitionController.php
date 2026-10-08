<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SpecDefinitionRequest;
use App\Http\Resources\Catalog\SpecDefinitionResource;
use App\Models\Category;
use App\Models\SpecDefinition;
use App\Services\Catalog\TaxonomyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SpecDefinitionController extends Controller
{
    public function __construct(private readonly TaxonomyService $taxonomy) {}

    public function store(SpecDefinitionRequest $request, Category $category): JsonResponse
    {
        $definition = $this->taxonomy->createSpecDefinition($category, $request->validated(), $request->user());

        return (new SpecDefinitionResource($definition))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(SpecDefinitionRequest $request, SpecDefinition $specDefinition): SpecDefinitionResource
    {
        return new SpecDefinitionResource($this->taxonomy->updateSpecDefinition($specDefinition, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, SpecDefinition $specDefinition): Response
    {
        $this->taxonomy->deleteSpecDefinition($specDefinition, $request->user());

        return response()->noContent();
    }
}
