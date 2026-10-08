<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SpecDefinition;
use App\Services\Catalog\SpecificationValidator;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo catalog: categories, their spec definitions, brands and ~200 realistic products.
 * Every product's specs go through the same SpecificationValidator the admin API uses,
 * so a typo in the data files fails loudly here instead of corrupting the catalog.
 * Safe to run repeatedly (everything is keyed by slug / SKU).
 */
class CatalogSeeder extends Seeder
{
    /** Prefix used when generating SKUs, per category slug. */
    private const SKU_PREFIX = [
        'cpu' => 'CPU', 'motherboard' => 'MB', 'ram' => 'RAM', 'gpu' => 'GPU', 'storage' => 'STO', 'psu' => 'PSU',
        'pc-case' => 'CASE', 'cpu-cooler' => 'COOL', 'case-fan' => 'FAN', 'monitor' => 'MON', 'keyboard' => 'KB',
        'mouse' => 'MS', 'headset' => 'HS', 'accessories' => 'ACC',
    ];

    public function run(SpecificationValidator $validator): void
    {
        DB::transaction(function () use ($validator) {
            $this->seedCategories();

            foreach (Category::with('specDefinitions')->ordered()->get() as $category) {
                $rows = require __DIR__."/data/catalog/products/{$category->slug}.php";

                foreach ($rows as $index => $row) {
                    $this->seedProduct($validator, $category, $row, $index + 1);
                }
            }
        });
    }

    private function seedCategories(): void
    {
        foreach (require __DIR__.'/data/catalog/categories.php' as $order => $data) {
            $category = Category::updateOrCreate(['slug' => $data['slug']], [
                'name' => $data['name'],
                'description' => $data['description'],
                'builder_slot' => $data['builder_slot'],
                'sort_order' => $order,
                'is_active' => true,
            ]);

            foreach ($data['specs'] as $position => $spec) {
                SpecDefinition::updateOrCreate(
                    ['category_id' => $category->id, 'key' => $spec['key']],
                    [...$spec, 'sort_order' => $position],
                );
            }
        }
    }

    /** @param  array<string, mixed>  $row */
    private function seedProduct(SpecificationValidator $validator, Category $category, array $row, int $number): void
    {
        $brand = Brand::firstOrCreate(['slug' => Str::slug($row['brand'])], ['name' => $row['brand']]);
        $sku = sprintf('%s-%s-%03d', self::SKU_PREFIX[$category->slug], strtoupper(substr(Str::slug($row['brand'], ''), 0, 3)), $number);
        $specRows = $validator->normalize($category, $row['specs']);

        $product = Product::withTrashed()->updateOrCreate(['sku' => $sku], [
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'slug' => Str::slug("{$row['brand']} {$row['name']}"),
            'name' => $row['name'] === '' ? $row['brand'] : "{$row['brand']} {$row['name']}",
            'description' => $this->describe($row, $category),
            'price' => Money::fromRinggit((string) $row['price']),
            'discount_price' => $row['discount'] === null ? null : Money::fromRinggit((string) $row['discount']),
            'status' => ProductStatus::Active,
            'weight_grams' => $row['weight'],
            'warranty_months' => $row['warranty'],
            'warranty_info' => $row['warranty'] ? "{$row['warranty']} months manufacturer warranty" : null,
            // Stable pseudo-random popularity so the "Popular" sort looks natural but never changes between runs.
            'sold_count' => crc32($sku) % 480,
        ]);

        $product->restore();
        $product->specifications()->whereNotIn('key', array_keys($specRows))->delete();

        foreach ($specRows as $key => $values) {
            $product->specifications()->updateOrCreate(['key' => $key], $values);
        }

        Inventory::updateOrCreate(['product_id' => $product->id], [
            'stock' => $row['stock'],
            'reserved' => 0,
            'low_stock_threshold' => 5,
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private function describe(array $row, Category $category): string
    {
        $highlights = collect($category->specDefinitions)
            ->where('show_on_card', true)
            ->filter(fn (SpecDefinition $d) => isset($row['specs'][$d->key]))
            ->map(fn (SpecDefinition $d) => $d->label.': '.trim((is_array($row['specs'][$d->key]) ? implode('/', $row['specs'][$d->key]) : $row['specs'][$d->key]).' '.$d->unit))
            ->implode(' · ');

        return "{$row['brand']} {$row['name']}. {$highlights}.";
    }
}
