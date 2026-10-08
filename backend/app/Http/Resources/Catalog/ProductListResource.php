<?php

namespace App\Http\Resources\Catalog;

use App\Models\Product;
use App\Models\SpecDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact product shape used on cards and in lists.
 * Requires: brand, category.specDefinitions, images, inventory, specifications to be eager loaded.
 *
 * @mixin Product
 */
class ProductListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $values = $this->specValues();
        $definitions = $this->category->specDefinitions;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'name' => $this->name,
            'status' => $this->status->value,
            'brand' => ['slug' => $this->brand->slug, 'name' => $this->brand->name],
            'category' => ['slug' => $this->category->slug, 'name' => $this->category->name],
            'price' => $this->price,
            'discount_price' => $this->discount_price,
            'effective_price' => $this->effective_price,
            'on_sale' => $this->isOnSale(),
            'image' => $this->images->first()?->url,
            'highlights' => $definitions->where('show_on_card', true)
                ->filter(fn (SpecDefinition $d) => isset($values[$d->key]))
                ->map(fn (SpecDefinition $d) => ['key' => $d->key, 'label' => $d->label, 'display' => ProductResource::display($d, $values[$d->key])])
                ->values(),
            ...ProductResource::availability($this->resource, $request),
        ];
    }
}
