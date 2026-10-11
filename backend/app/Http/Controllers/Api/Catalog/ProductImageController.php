<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductImageRequest;
use App\Http\Resources\Catalog\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProductImageController extends Controller
{
    public function __construct(private readonly ProductImageService $images) {}

    /**
     * Upload a product photo.
     *
     * Upload a photo for a product. It is re-saved as WebP under a random name.
     */
    public function store(StoreProductImageRequest $request, Product $product): JsonResponse
    {
        $image = $this->images->store(
            $product,
            $request->file('image'),
            $request->input('alt'),
            $request->boolean('is_primary'),
            $request->user(),
        );

        return (new ProductImageResource($image))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Delete a product photo. */
    public function destroy(Request $request, Product $product, ProductImage $image): Response
    {
        // Never delete an image through another product's URL.
        abort_unless($image->product_id === $product->id, 404);

        $this->images->delete($image, $request->user());

        return response()->noContent();
    }
}
