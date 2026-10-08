<?php

namespace App\Services\Catalog;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All manual stock changes go through here. Each change locks the inventory row, so two admins
 * (or an admin and a customer checkout later) can never overwrite each other or oversell.
 */
class InventoryService
{
    public const REASONS = ['restock', 'adjustment', 'correction', 'return'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException when the change would push stock below zero or below reserved units
     */
    public function adjust(Product $product, int $change, string $reason, ?string $note, User $actor): Inventory
    {
        return DB::transaction(function () use ($product, $change, $reason, $note, $actor) {
            // SELECT ... FOR UPDATE: other transactions wait here until we commit.
            $inventory = Inventory::where('product_id', $product->id)->lockForUpdate()->firstOrFail();

            $newStock = $inventory->stock + $change;

            if ($newStock < $inventory->reserved) {
                throw ValidationException::withMessages([
                    'change' => $newStock < 0
                        ? 'Stock cannot go below zero.'
                        : "Stock cannot go below the {$inventory->reserved} unit(s) reserved for unpaid orders.",
                ]);
            }

            $oldStock = $inventory->stock;
            $inventory->update(['stock' => $newStock]);

            InventoryMovement::create([
                'product_id' => $product->id,
                'user_id' => $actor->id,
                'change' => $change,
                'stock_after' => $newStock,
                'reason' => $reason,
                'note' => $note,
            ]);

            $this->audit->log('inventory.adjusted', $actor, $product, ['stock' => $oldStock], ['stock' => $newStock, 'reason' => $reason]);

            return $inventory;
        });
    }
}
