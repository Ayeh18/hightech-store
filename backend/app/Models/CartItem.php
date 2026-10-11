<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CartItemKind;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cart_id', 'kind', 'product_id', 'pc_build_id', 'name', 'quantity', 'price_when_added'])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['kind' => CartItemKind::class, 'price_when_added' => MoneyCast::class];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Includes soft-deleted products, so the cart can still say "this product was withdrawn".
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<PcBuild, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(PcBuild::class, 'pc_build_id');
    }

    /** @return HasMany<CartItemComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(CartItemComponent::class)->orderBy('id');
    }

    public function isBuild(): bool
    {
        return $this->kind === CartItemKind::Build;
    }
}
