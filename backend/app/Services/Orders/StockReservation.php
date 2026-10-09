<?php

namespace App\Services\Orders;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Holds stock for an unpaid order by raising `reserved` (available = stock - reserved), and gives it back.
 *
 * Every call locks the affected inventory rows (SELECT ... FOR UPDATE) in a fixed order. The lock is what stops two
 * customers buying the last unit at the same time: the second one waits, then sees nothing left. The fixed order
 * stops two checkouts that share products from deadlocking. A database CHECK (reserved <= stock) is the last line of defence.
 */
class StockReservation
{
    /**
     * @param  array<int, int>  $demand  product id => units needed
     *
     * @throws ValidationException when any product cannot cover its units; nothing is reserved in that case
     */
    public function reserve(array $demand): void
    {
        $rows = $this->lock($demand);
        $short = [];

        foreach ($demand as $productId => $units) {
            $available = $rows->get($productId)?->available ?? 0;

            if ($available < $units) {
                $short[$productId] = $available;
            }
        }

        if ($short !== []) {
            $names = Product::withTrashed()->whereIn('id', array_keys($short))->pluck('name', 'id');
            $messages = [];

            foreach ($short as $productId => $available) {
                $messages[] = $available > 0
                    ? "{$names[$productId]}: only {$available} left, but your order needs {$demand[$productId]}."
                    : "{$names[$productId]} is out of stock.";
            }

            throw ValidationException::withMessages(['cart' => $messages]);
        }

        foreach ($demand as $productId => $units) {
            $rows[$productId]->increment('reserved', $units);
        }
    }

    /** @param  array<int, int>  $demand  product id => units to give back */
    public function release(array $demand): void
    {
        $rows = $this->lock($demand);

        foreach ($demand as $productId => $units) {
            $inventory = $rows->get($productId);

            if ($inventory) {
                $inventory->update(['reserved' => max(0, $inventory->reserved - $units)]);
            }
        }
    }

    /**
     * The order has been paid: the reserved units are now SOLD. Stock goes down by the units and the reservation is
     * released by the same amount (so `available` does not change, it was already held for this order), and every
     * product gets a line in its stock history. Both columns change in one UPDATE, so reserved <= stock always holds.
     *
     * @param  array<int, int>  $demand  product id => units sold
     */
    public function commit(array $demand, Order $order): void
    {
        $rows = $this->lock($demand);

        foreach ($demand as $productId => $units) {
            $inventory = $rows->get($productId);

            if ($inventory === null || $inventory->stock < $units) {
                // The units were reserved when the order was placed, so this means data was changed behind our back.
                throw new LogicException("Cannot sell {$units} of product {$productId} for {$order->order_number}: not enough stock.");
            }

            $inventory->update([
                'stock' => $inventory->stock - $units,
                'reserved' => max(0, $inventory->reserved - $units),
            ]);

            InventoryMovement::create([
                'product_id' => $productId,
                'user_id' => null,
                'change' => -$units,
                'stock_after' => $inventory->stock,
                'reason' => 'sale',
                'note' => "Order {$order->order_number}",
            ]);
        }
    }

    /** @param  array<int, int>  $demand */
    private function lock(array $demand)
    {
        $ids = array_keys($demand);
        sort($ids);

        return Inventory::whereIn('product_id', $ids)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
    }
}
