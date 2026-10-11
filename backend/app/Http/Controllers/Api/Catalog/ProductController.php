<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductIndexRequest;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\Catalog\ProductListResource;
use App\Http\Resources\Catalog\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductSearch;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductSearch $search,
        private readonly ProductService $products,
    ) {}

    /**
     * List published products.
     *
     * Public shop listing: only published products.
     */
    public function index(ProductIndexRequest $request): AnonymousResourceCollection
    {
        return ProductListResource::collection($this->search->paginate($request->filters()));
    }

    /**
     * List products for staff, drafts included.
     *
     * Admin listing: includes drafts and archived products, filterable by status.
     */
    public function adminIndex(ProductIndexRequest $request): AnonymousResourceCollection
    {
        return ProductListResource::collection($this->search->paginate($request->filters(), includeHidden: true));
    }

    /**
     * Show one product.
     *
     * Show one product. A product that is not published answers 404 to customers.
     */
    public function show(Request $request, Product $product): ProductResource
    {
        $canSeeHidden = (bool) $request->user('sanctum')?->can('products.manage');

        // Pretend hidden products do not exist for customers (404, not 403).
        abort_unless($canSeeHidden || $this->isPublic($product), 404);

        return new ProductResource($product->load('brand', 'category.specDefinitions', 'images', 'inventory', 'specifications'));
    }

    /** Create a product. */
    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated(), $request->user());

        return (new ProductResource($product))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Change a product. */
    public function update(ProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource($this->products->update($product, $request->validated(), $request->user()));
    }

    /** Delete a product. */
    public function destroy(Request $request, Product $product): Response
    {
        $this->products->delete($product, $request->user());

        return response()->noContent();
    }

    private function isPublic(Product $product): bool
    {
        return $product->status->value === 'active' && $product->category()->where('is_active', true)->exists();
    }
}
