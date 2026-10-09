<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BuilderSlot;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'product_id', 'sku', 'name', 'quantity', 'unit_price', 'line_total', 'slot', 'build_group', 'build_name', 'build_copies'])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['unit_price' => MoneyCast::class, 'line_total' => MoneyCast::class, 'slot' => BuilderSlot::class];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
