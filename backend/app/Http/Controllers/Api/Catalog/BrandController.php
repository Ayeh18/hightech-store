<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BrandRequest;
use App\Http\Resources\Catalog\BrandResource;
use App\Models\Brand;
use App\Services\Catalog\TaxonomyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BrandController extends Controller
{
    public function __construct(private readonly TaxonomyService $taxonomy) {}

    public function index(): AnonymousResourceCollection
    {
        return BrandResource::collection(
            Brand::query()
                ->withCount(['products' => fn ($q) => $q->where('status', ProductStatus::Active->value)])
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(BrandRequest $request): JsonResponse
    {
        $brand = $this->taxonomy->createBrand($request->validated(), $request->user());

        return (new BrandResource($brand))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(BrandRequest $request, Brand $brand): BrandResource
    {
        return new BrandResource($this->taxonomy->updateBrand($brand, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, Brand $brand): Response
    {
        $this->taxonomy->deleteBrand($brand, $request->user());

        return response()->noContent();
    }
}
