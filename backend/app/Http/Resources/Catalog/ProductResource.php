<?php

namespace App\Http\Resources\Catalog;

use App\Enums\SpecType;
use App\Models\Product;
use App\Models\SpecDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full product shape for the detail page and admin responses.
 * Requires: brand, category.specDefinitions, images, inventory, specifications to be eager loaded.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $values = $this->specValues();

        return [
            ...(new ProductListResource($this->resource))->toArray($request),
            'description' => $this->description,
            'weight_grams' => $this->weight_grams,
            'warranty_months' => $this->warranty_months,
            'warranty_info' => $this->warranty_info,
            'images' => ProductImageResource::collection($this->images),
            // Labels, units and order come from spec_definitions, so the frontend never hardcodes them.
            'specifications' => $this->category->specDefinitions
                ->filter(fn (SpecDefinition $d) => isset($values[$d->key]))
                ->map(fn (SpecDefinition $d) => [
                    'key' => $d->key,
                    'label' => $d->label,
                    'value' => $values[$d->key],
                    'unit' => $d->unit,
                    'display' => self::display($d, $values[$d->key]),
                ])
                ->values(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** "6 cores", "AM5", "ATX, Micro ATX" */
    public static function display(SpecDefinition $definition, mixed $value): string
    {
        return match ($definition->type) {
            SpecType::List => implode(', ', (array) $value),
            SpecType::Number => trim($value.' '.$definition->unit),
            default => (string) $value,
        };
    }

    /**
     * What a customer may know about stock: whether it is available, and a "low stock" warning.
     * Exact quantities are only included for staff who manage inventory.
     *
     * @return array<string, mixed>
     */
    public static function availability(Product $product, Request $request): array
    {
        $inventory = $product->inventory;
        $available = $inventory?->available ?? 0;

        $data = [
            'in_stock' => $available > 0,
            'low_stock' => $inventory?->isLowStock() ?? false,
        ];

        if ($request->user('sanctum')?->can('inventory.manage')) {
            $data['inventory'] = [
                'stock' => $inventory?->stock ?? 0,
                'reserved' => $inventory?->reserved ?? 0,
                'available' => $available,
                'low_stock_threshold' => $inventory?->low_stock_threshold ?? 0,
            ];
        }

        return $data;
    }
}
