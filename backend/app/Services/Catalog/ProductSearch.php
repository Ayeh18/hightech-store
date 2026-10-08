<?php

namespace App\Services\Catalog;

use App\Enums\SpecType;
use App\Models\Category;
use App\Models\Product;
use App\Models\SpecDefinition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the product list query: search, filters, sorting and pagination.
 * One class owns this so the public shop and the admin list behave identically.
 */
class ProductSearch
{
    /**
     * @param  bool  $includeHidden  admin view: drafts, archived products and inactive categories too
     */
    public function paginate(ProductFilters $filters, bool $includeHidden = false): LengthAwarePaginator
    {
        $query = Product::query()->with([
            'brand', 'category.specDefinitions', 'images', 'inventory', 'specifications',
        ]);

        if ($includeHidden) {
            if ($filters->status !== null) {
                $query->where('products.status', $filters->status);
            }
        } else {
            $query->visible();
        }

        $this->applySearch($query, $filters->search);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate($filters->perPage)->withQueryString();
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        // Every word must match somewhere (name, SKU, brand or category), in any order.
        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $like = '%'.$this->escapeLike($term).'%';

            $query->where(fn (Builder $q) => $q
                ->where('products.name', 'ilike', $like)
                ->orWhere('products.sku', 'ilike', $like)
                ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'ilike', $like))
                ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'ilike', $like)));
        }
    }

    private function applyFilters(Builder $query, ProductFilters $filters): void
    {
        if ($filters->category !== null) {
            $query->whereHas('category', fn (Builder $q) => $q->where('slug', $filters->category));
        }

        if ($filters->brands !== []) {
            $query->whereHas('brand', fn (Builder $q) => $q->whereIn('slug', $filters->brands));
        }

        // Filter on the price the customer really pays (discount price when there is one).
        if ($filters->priceMin !== null) {
            $query->whereRaw('COALESCE(products.discount_price, products.price) >= ?', [$filters->priceMin]);
        }

        if ($filters->priceMax !== null) {
            $query->whereRaw('COALESCE(products.discount_price, products.price) <= ?', [$filters->priceMax]);
        }

        if ($filters->inStock) {
            $query->whereHas('inventory', fn (Builder $q) => $q->whereRaw('stock - reserved > 0'));
        }

        $this->applySpecFilters($query, $filters);
    }

    /** Spec filters only make sense inside one category, and only for definitions marked filterable. */
    private function applySpecFilters(Builder $query, ProductFilters $filters): void
    {
        if ($filters->specs === [] || $filters->category === null) {
            return;
        }

        $definitions = Category::where('slug', $filters->category)->first()
            ?->specDefinitions()->where('is_filterable', true)->get()->keyBy('key');

        foreach ($filters->specs as $key => $condition) {
            $definition = $definitions?->get($key);

            if ($definition === null || ! is_array($condition) || $condition === []) {
                continue;
            }

            $query->whereHas('specifications', fn (Builder $q) => $this->specCondition($q->where('key', $key), $definition, $condition));
        }
    }

    /** @param  array<int|string, mixed>  $condition */
    private function specCondition(Builder $query, SpecDefinition $definition, array $condition): void
    {
        match ($definition->type) {
            SpecType::Number => $query
                ->when(isset($condition['min']) && is_numeric($condition['min']), fn ($q) => $q->where('value_number', '>=', $condition['min']))
                ->when(isset($condition['max']) && is_numeric($condition['max']), fn ($q) => $q->where('value_number', '<=', $condition['max'])),
            SpecType::List => $query->where(function (Builder $q) use ($condition) {
                foreach (array_filter($condition, 'is_string') as $value) {
                    $q->orWhereRaw('value_json @> ?::jsonb', [json_encode([$value])]);
                }
            }),
            default => $query->whereIn('value_string', array_filter($condition, 'is_string')),
        };
    }

    private function applySort(Builder $query, ProductFilters $filters): void
    {
        $sort = $filters->sort;

        // A text search with no explicit sort lists name matches that start with the text first.
        if ($sort === null && $filters->search) {
            $query->orderByRaw('(products.name ILIKE ?) DESC', [$this->escapeLike($filters->search).'%']);
        }

        match ($sort) {
            'price_asc' => $query->orderByRaw('COALESCE(products.discount_price, products.price) ASC'),
            'price_desc' => $query->orderByRaw('COALESCE(products.discount_price, products.price) DESC'),
            'newest' => $query->orderByDesc('products.created_at'),
            default => $query->orderByDesc('products.sold_count'),
        };

        $query->orderBy('products.id'); // stable order so pages never repeat or skip items
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
