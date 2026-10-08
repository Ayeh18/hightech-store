<?php

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Enums\SpecType;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Describes which filters the shop can show for a category, built entirely from database data:
 * brands, price range and the spec definitions marked filterable, each with real option counts.
 */
class CatalogFilterService
{
    /** @return array<string, mixed> */
    public function forCategory(Category $category): array
    {
        $visible = fn () => DB::table('products')
            ->where('products.category_id', $category->id)
            ->where('products.status', ProductStatus::Active->value)
            ->whereNull('products.deleted_at');

        $price = $visible()->selectRaw('MIN(COALESCE(discount_price, price)) AS min, MAX(COALESCE(discount_price, price)) AS max')->first();

        $brands = $visible()
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->groupBy('brands.id', 'brands.slug', 'brands.name')
            ->orderBy('brands.name')
            ->get(['brands.slug', 'brands.name', DB::raw('COUNT(*) AS count')])
            ->map(fn ($row) => ['slug' => $row->slug, 'name' => $row->name, 'count' => (int) $row->count])
            ->all();

        $specs = $category->specDefinitions
            ->where('is_filterable', true)
            ->map(fn ($definition) => $this->specFilter($category, $definition))
            ->values()
            ->all();

        return [
            'category' => ['slug' => $category->slug, 'name' => $category->name],
            'price' => ['min' => (int) ($price->min ?? 0), 'max' => (int) ($price->max ?? 0)],
            'brands' => $brands,
            'specs' => $specs,
        ];
    }

    /** @return array<string, mixed> */
    private function specFilter(Category $category, $definition): array
    {
        $base = [
            'key' => $definition->key,
            'label' => $definition->label,
            'type' => $definition->type->value,
            'unit' => $definition->unit,
        ];

        $specs = DB::table('product_specifications AS ps')
            ->join('products', 'products.id', '=', 'ps.product_id')
            ->where('products.category_id', $category->id)
            ->where('products.status', ProductStatus::Active->value)
            ->whereNull('products.deleted_at')
            ->where('ps.key', $definition->key);

        if ($definition->type === SpecType::Number) {
            $range = $specs->selectRaw('MIN(ps.value_number) AS min, MAX(ps.value_number) AS max')->first();

            return $base + ['min' => (float) ($range->min ?? 0), 'max' => (float) ($range->max ?? 0)];
        }

        $options = $definition->type === SpecType::List
            ? $specs->selectRaw('jsonb_array_elements_text(ps.value_json) AS value')->get()->countBy('value')
            : $specs->get(['ps.value_string AS value'])->countBy('value');

        // Show options in the order the definition lists them, unknown ones last (alphabetical).
        $order = array_flip($definition->options ?? []);
        $sorted = $options->sortKeysUsing(fn ($a, $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return $base + [
            'options' => $sorted->map(fn ($count, $value) => ['value' => (string) $value, 'count' => $count])->values()->all(),
        ];
    }
}
