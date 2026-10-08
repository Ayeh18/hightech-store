<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates, updates and deletes products together with their specifications, stock row and audit trail. */
class ProductService
{
    public function __construct(
        private readonly SpecificationValidator $specs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data (prices in sen)
     */
    public function create(array $data, User $actor): Product
    {
        $category = Category::with('specDefinitions')->findOrFail($data['category_id']);
        $specRows = $this->specs->normalize($category, $data['specs'] ?? []);

        return DB::transaction(function () use ($data, $category, $specRows, $actor) {
            $product = Product::create([
                ...$this->attributes($data),
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
            ]);

            $this->writeSpecs($product, $specRows);

            Inventory::create([
                'product_id' => $product->id,
                'stock' => 0,
                'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
            ]);

            $this->audit->log('product.created', $actor, $product, null, [
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $category->slug,
                'price' => $product->price->sen,
            ]);

            return $this->fresh($product);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, User $actor): Product
    {
        // Specs are only re-validated when sent. The request requires them when the category changes.
        $specRows = null;

        if (array_key_exists('specs', $data)) {
            $category = Category::with('specDefinitions')->findOrFail($data['category_id'] ?? $product->category_id);
            $specRows = $this->specs->normalize($category, $data['specs']);
        }

        return DB::transaction(function () use ($product, $data, $specRows, $actor) {
            $old = $this->snapshot($product);

            $product->fill($this->attributes($data, $product));

            if (isset($data['slug']) && $data['slug'] !== $product->slug) {
                $product->slug = $this->uniqueSlug($data['slug'], $product->id);
            }

            $product->save();

            if ($specRows !== null) {
                $this->writeSpecs($product, $specRows);
            }

            if (isset($data['low_stock_threshold'])) {
                Inventory::where('product_id', $product->id)->update(['low_stock_threshold' => $data['low_stock_threshold']]);
            }

            $new = $this->snapshot($product);

            if ($old['price'] !== $new['price'] || $old['discount_price'] !== $new['discount_price']) {
                $this->audit->log('product.price_changed', $actor, $product,
                    ['price' => $old['price'], 'discount_price' => $old['discount_price']],
                    ['price' => $new['price'], 'discount_price' => $new['discount_price']],
                );
            }

            $changed = array_keys(array_diff_assoc($new, $old));
            $other = array_values(array_diff($changed, ['price', 'discount_price']));

            if ($other !== [] || $specRows !== null) {
                $this->audit->log('product.updated', $actor, $product,
                    array_intersect_key($old, array_flip($other)),
                    array_intersect_key($new, array_flip($other)),
                );
            }

            return $this->fresh($product);
        });
    }

    /** Soft delete: the row stays so past orders can still reference the product. */
    public function delete(Product $product, User $actor): void
    {
        DB::transaction(function () use ($product, $actor) {
            $this->audit->log('product.deleted', $actor, $product, ['sku' => $product->sku, 'name' => $product->name]);
            $product->delete();
        });
    }

    /** @return array<string, mixed> */
    private function attributes(array $data, ?Product $existing = null): array
    {
        $attributes = collect($data)->only([
            'category_id', 'brand_id', 'sku', 'name', 'description', 'status',
            'weight_grams', 'warranty_months', 'warranty_info',
        ])->all();

        foreach (['price', 'discount_price'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] === null ? null : Money::fromSen((int) $data[$field]);
            }
        }

        return $attributes;
    }

    /** @return array<string, mixed> comparable, audit-safe view of the product */
    private function snapshot(Product $product): array
    {
        return [
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->price->sen,
            'discount_price' => $product->discount_price?->sen,
            'status' => $product->status->value,
            'weight_grams' => $product->weight_grams,
            'warranty_months' => $product->warranty_months,
            'warranty_info' => $product->warranty_info,
        ];
    }

    /** @param  array<string, array<string, mixed>>  $rows */
    private function writeSpecs(Product $product, array $rows): void
    {
        $product->specifications()->whereNotIn('key', array_keys($rows))->delete();

        foreach ($rows as $key => $values) {
            $product->specifications()->updateOrCreate(['key' => $key], $values);
        }
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'product';
        $slug = $base;
        $i = 2;

        while (Product::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function fresh(Product $product): Product
    {
        return $product->fresh(['category.specDefinitions', 'brand', 'images', 'specifications', 'inventory']);
    }
}
