<?php

namespace App\Services\Catalog;

/** Everything a product list request can ask for, already validated. Prices are in sen. */
final readonly class ProductFilters
{
    public const SORTS = ['relevance', 'popular', 'newest', 'price_asc', 'price_desc'];

    /**
     * @param  array<int, string>  $brands  brand slugs
     * @param  array<string, mixed>  $specs  spec key => list of values, or ['min' => x, 'max' => y]
     */
    public function __construct(
        public ?string $search = null,
        public ?string $category = null,
        public array $brands = [],
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public bool $inStock = false,
        public array $specs = [],
        public ?string $sort = null,
        public ?string $status = null,
        public int $perPage = 12,
    ) {}

    /** The same filters, restricted to one category (the builder fixes the category from the slot). */
    public function withCategory(string $category): self
    {
        return new self(
            $this->search, $category, $this->brands, $this->priceMin, $this->priceMax,
            $this->inStock, $this->specs, $this->sort, $this->status, $this->perPage,
        );
    }

    /** @param  array<string, mixed>  $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            search: isset($validated['q']) ? trim($validated['q']) : null,
            category: $validated['category'] ?? null,
            brands: array_values($validated['brands'] ?? []),
            priceMin: isset($validated['price_min']) ? (int) $validated['price_min'] : null,
            priceMax: isset($validated['price_max']) ? (int) $validated['price_max'] : null,
            inStock: (bool) ($validated['in_stock'] ?? false),
            specs: $validated['specs'] ?? [],
            sort: $validated['sort'] ?? null,
            status: $validated['status'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 12),
        );
    }
}
