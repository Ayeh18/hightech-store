<?php

namespace App\Observers;

use App\Enums\ProductStatus;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStock;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the people who manage stock when a product DROPS to its alert level. Only the crossing counts (from "fine" to
 * "low"), so a product that stays low does not send a message with every sale, and a product that is restocked and runs
 * low again does.
 *
 * Selling a reserved unit (stock and reserved both fall) does not change what can be sold, so it stays quiet: the warning
 * was already sent when the unit was reserved.
 */
class InventoryObserver
{
    public function updated(Inventory $inventory): void
    {
        if (! $inventory->wasChanged(['stock', 'reserved'])) {
            return;
        }

        $before = max(0, (int) $inventory->getOriginal('stock') - (int) $inventory->getOriginal('reserved'));
        $after = $inventory->available;
        $threshold = (int) $inventory->low_stock_threshold;

        if (! ($before > $threshold && $after <= $threshold)) {
            return;
        }

        // Looked up by id, not through a relation: inventories are often loaded several at a time, where lazy loading is forbidden.
        $product = Product::find($inventory->product_id);

        if ($product === null || $product->status !== ProductStatus::Active) {
            return;
        }

        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'inventory.manage'))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new LowStock($product, $after, $threshold));
        }
    }
}
