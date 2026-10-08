<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\AdjustInventoryRequest;
use App\Models\Product;
use App\Services\Catalog\InventoryService;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function adjust(AdjustInventoryRequest $request, Product $product): JsonResponse
    {
        $inventory = $this->inventory->adjust(
            $product,
            $request->integer('change'),
            $request->string('reason')->toString(),
            $request->input('note'),
            $request->user(),
        );

        return response()->json(['data' => [
            'stock' => $inventory->stock,
            'reserved' => $inventory->reserved,
            'available' => $inventory->available,
            'low_stock_threshold' => $inventory->low_stock_threshold,
        ]]);
    }
}
