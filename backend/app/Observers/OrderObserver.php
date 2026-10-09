<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderStatusHistory;

/**
 * Writes a line to the order's status history every time the status changes, whatever caused it (a customer, staff, a
 * payment webhook, the expiry job). Doing it here, once, means no code path can forget.
 */
class OrderObserver
{
    public function created(Order $order): void
    {
        $this->record($order, null);
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('status')) {
            $this->record($order, $order->getOriginal('status'));
        }
    }

    private function record(Order $order, mixed $from): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $from?->value,
            'to_status' => $order->status->value,
            // Webhooks, jobs and the console have no logged-in user: those changes are the system's.
            'user_id' => auth()->id(),
        ]);
    }
}
