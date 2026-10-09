<?php

namespace App\Models;

use App\Observers\InventoryObserver;
use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(InventoryObserver::class)]
#[Fillable(['product_id', 'stock', 'reserved', 'low_stock_threshold'])]
class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Units that can still be sold: stock minus units reserved by unpaid orders. */
    public function getAvailableAttribute(): int
    {
        return max(0, $this->stock - $this->reserved);
    }

    public function isLowStock(): bool
    {
        return $this->available > 0 && $this->available <= $this->low_stock_threshold;
    }
}
