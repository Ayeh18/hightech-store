<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ProductStatus;
use App\Support\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'category_id', 'brand_id', 'sku', 'slug', 'name', 'description', 'price', 'discount_price',
    'status', 'weight_grams', 'warranty_months', 'warranty_info',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'discount_price' => MoneyCast::class,
            'status' => ProductStatus::class,
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    /** @return HasMany<ProductSpecification, $this> */
    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class);
    }

    /** @return HasOne<Inventory, $this> */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    /** Products a customer is allowed to see: published, in a published category. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('products.status', ProductStatus::Active->value)
            ->whereHas('category', fn (Builder $q) => $q->where('is_active', true));
    }

    /** The price a customer actually pays: the discount price when there is one. */
    public function getEffectivePriceAttribute(): Money
    {
        return $this->discount_price ?? $this->price;
    }

    public function isOnSale(): bool
    {
        return $this->discount_price !== null;
    }

    /**
     * Specification values keyed by spec key, e.g. ['socket' => 'AM5', 'cores' => 6].
     * Needs the `specifications` relation to be eager loaded.
     *
     * @return array<string, string|int|float|array<int, string>|null>
     */
    public function specValues(): array
    {
        return $this->specifications->mapWithKeys(fn (ProductSpecification $s) => [$s->key => $s->value])->all();
    }

    public function spec(string $key): string|int|float|array|null
    {
        return $this->specValues()[$key] ?? null;
    }

    /** Lets URLs and API calls use either the numeric id (/products/12) or the slug (/products/ryzen-5-7600). */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        return $this->newQuery()
            ->where(ctype_digit((string) $value) ? 'id' : 'slug', $value)
            ->first();
    }
}
