<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BuilderSlot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cart_item_id', 'product_id', 'slot', 'quantity', 'unit_price'])]
class CartItemComponent extends Model
{
    protected function casts(): array
    {
        return ['slot' => BuilderSlot::class, 'unit_price' => MoneyCast::class];
    }

    /** @return BelongsTo<CartItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CartItem::class, 'cart_item_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
