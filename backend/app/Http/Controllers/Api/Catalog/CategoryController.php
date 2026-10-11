<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Http\Resources\Catalog\CategoryResource;
use App\Models\Category;
use App\Services\Catalog\CatalogFilterService;
use App\Services\Catalog\TaxonomyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function __construct(private readonly TaxonomyService $taxonomy) {}

    /** List categories. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $canSeeInactive = (bool) $request->user('sanctum')?->can('categories.manage');

        $categories = Category::query()
            ->when(! $canSeeInactive, fn ($q) => $q->active())
            ->withCount(['products' => fn ($q) => $q->where('status', ProductStatus::Active->value)])
            ->ordered()
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Show one category and its specifications.
     *
     * Show one category with the specifications its products have.
     */
    public function show(Request $request, Category $category): CategoryResource
    {
        abort_unless($category->is_active || $request->user('sanctum')?->can('categories.manage'), 404);

        return new CategoryResource($category->load('specDefinitions'));
    }

    /**
     * The filters the shop can offer for a category.
     *
     * Filters the shop can offer for this category (brands, price range, specs), all from the database.
     */
    public function filters(Request $request, Category $category, CatalogFilterService $filters): JsonResponse
    {
        abort_unless($category->is_active || $request->user('sanctum')?->can('categories.manage'), 404);

        return response()->json(['data' => $filters->forCategory($category->load('specDefinitions'))]);
    }

    /** Create a category. */
    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->taxonomy->createCategory($request->validated(), $request->user());

        return (new CategoryResource($category))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Change a category. */
    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        return new CategoryResource($this->taxonomy->updateCategory($category, $request->validated(), $request->user()));
    }

    /** Delete a category. */
    public function destroy(Request $request, Category $category): Response
    {
        $this->taxonomy->deleteCategory($category, $request->user());

        return response()->noContent();
    }
}
