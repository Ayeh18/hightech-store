<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\OrderDelivered;
use App\Notifications\OrderShipped;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staff getting a paid order to the customer: PAID -> PROCESSING -> PACKED -> SHIPPED -> DELIVERED.
 * Each step is strictly the next one (even where OrderStatus would technically allow more), and every step locks the
 * order, so two staff members clicking at once cannot both ship it.
 */
class FulfilmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Paid -> Processing (we have started on it) or Processing -> Packed (it is in the box). */
    public function advance(Order $order, OrderStatus $to, User $staff): Order
    {
        return DB::transaction(function () use ($order, $to, $staff) {
            $locked = $this->lock($order);
            $expectedNow = match ($to) {
                OrderStatus::Processing => OrderStatus::Paid,
                OrderStatus::Packed => OrderStatus::Processing,
                default => throw ValidationException::withMessages(['status' => 'Choose PROCESSING or PACKED. Shipping and delivery have their own actions.']),
            };

            $this->require($locked, $expectedNow, "move it to {$to->label()}");
            $this->change($locked, $to, $staff);

            return $locked;
        });
    }

    /** Packed -> Shipped, with the carrier and tracking number the customer will see. */
    public function ship(Order $order, string $carrier, string $trackingNumber, User $staff): Order
    {
        $shipment = null;

        $result = DB::transaction(function () use ($order, $carrier, $trackingNumber, $staff, &$shipment) {
            $locked = $this->lock($order);
            $this->require($locked, OrderStatus::Packed, 'ship it');

            $shipment = Shipment::create([
                'order_id' => $locked->id,
                'carrier' => trim($carrier),
                'tracking_number' => trim($trackingNumber),
                'shipped_by' => $staff->id,
                'shipped_at' => now(),
            ]);
            $this->change($locked, OrderStatus::Shipped, $staff, ['carrier' => $shipment->carrier, 'tracking_number' => $shipment->tracking_number]);

            return $locked->setRelation('shipment', $shipment);
        });

        try {
            $result->user->notify(new OrderShipped($result, $shipment));
        } catch (Throwable $e) {
            report($e); // an email problem must not undo a shipment that has left the warehouse
        }

        return $result;
    }

    /** Shipped -> Delivered. The refund window for the customer starts counting from here. */
    public function markDelivered(Order $order, User $staff): Order
    {
        $result = DB::transaction(function () use ($order, $staff) {
            $locked = $this->lock($order);
            $this->require($locked, OrderStatus::Shipped, 'mark it delivered');

            $locked->shipment->update(['delivered_at' => now()]);
            $this->change($locked, OrderStatus::Delivered, $staff);

            return $locked;
        });

        try {
            $result->user->notify(new OrderDelivered($result));
        } catch (Throwable $e) {
            report($e); // an email problem must not undo a delivery that already happened
        }

        return $result;
    }

    private function lock(Order $order): Order
    {
        return Order::whereKey($order->id)->lockForUpdate()->firstOrFail()->load(['shipment', 'user']);
    }

    private function require(Order $order, OrderStatus $expected, string $action): void
    {
        if ($order->status !== $expected) {
            throw ValidationException::withMessages(['order' => "An order that is \"{$order->status->label()}\" cannot {$action}. It must be \"{$expected->label()}\" first."]);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function change(Order $order, OrderStatus $to, User $staff, array $extra = []): void
    {
        $from = $order->status;
        $order->moveTo($to);
        $order->save();

        $this->audit->log('order.status_changed', $staff, $order, ['status' => $from->value], ['status' => $to->value, ...$extra]);
    }
}
