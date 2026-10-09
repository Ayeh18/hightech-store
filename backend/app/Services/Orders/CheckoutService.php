<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderPlaced;
use App\Services\AuditLogger;
use App\Services\Cart\CartEvaluator;
use App\Services\Cart\CartService;
use App\Services\Cart\EvaluatedCart;
use App\Services\PcBuilder\BuildIssue;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Turns a cart into an order.
 *
 * Nothing the browser sends decides a price. The order is built from the cart as the server sees it at this moment
 * (current prices, current stock, compatibility re-checked), the coupon is validated again, shipping is calculated
 * from the chosen address, and stock is reserved under a row lock. All of it happens in ONE database transaction:
 * either the whole order exists, or nothing changed.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CartEvaluator $evaluator,
        private readonly CouponService $coupons,
        private readonly ShippingCalculator $shipping,
        private readonly StockReservation $stock,
        private readonly OrderNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** What the order would cost now. Used by the checkout page to show totals as the customer picks an address or coupon. */
    public function quote(User $user, ?int $addressId, ?string $couponCode): CheckoutQuote
    {
        $evaluated = $this->evaluator->evaluate($this->carts->cartFor($user));
        $address = $addressId ? $user->addresses()->find($addressId) : null;
        $subtotal = $evaluated->subtotal;

        $application = null;
        $couponError = null;

        if ($couponCode !== null && trim($couponCode) !== '') {
            try {
                $application = $this->coupons->apply($couponCode, $user, $subtotal);
            } catch (ValidationException $e) {
                $couponError = $e->errors()['coupon_code'][0];
            }
        }

        $discount = $application?->discount ?? Money::zero();
        $afterDiscount = $subtotal->subtract($discount);
        $shipping = $address ? $this->shipping->cost($address->state, $afterDiscount) : null;

        return new CheckoutQuote(
            $evaluated, $address, $application?->coupon, $couponError, $subtotal, $discount, $shipping,
            $afterDiscount->add($shipping ?? Money::zero()),
            $this->blockers($evaluated, $address),
        );
    }

    /**
     * Places the order. A repeated request with the same idempotency key returns the order it already created
     * instead of creating another (double click, flaky network, retry).
     *
     * @throws ValidationException
     */
    public function place(User $user, int $addressId, ?string $couponCode, ?string $notes, ?string $idempotencyKey): PlacedOrder
    {
        $placed = $this->createOrder($user, $addressId, $couponCode, $notes, $idempotencyKey);

        // Only for an order that was really created now: a retry that was answered with the existing order stays quiet.
        // And a mail problem must never undo an order that is already safely placed.
        if ($placed->created) {
            try {
                $user->notify(new OrderPlaced($placed->order));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $placed;
    }

    /** @throws ValidationException */
    private function createOrder(User $user, int $addressId, ?string $couponCode, ?string $notes, ?string $idempotencyKey): PlacedOrder
    {
        $cart = $this->carts->cartFor($user);

        try {
            return DB::transaction(function () use ($user, $cart, $addressId, $couponCode, $notes, $idempotencyKey) {
                // Serialises this customer's checkouts: a second click waits here, then finds the first order.
                Cart::whereKey($cart->id)->lockForUpdate()->first();

                if ($existing = $this->alreadyPlaced($user, $idempotencyKey)) {
                    return new PlacedOrder($existing, false);
                }

                $evaluated = $this->evaluator->evaluate($cart);
                $address = Address::where('user_id', $user->id)->find($addressId);
                $blockers = $this->blockers($evaluated, $address);

                if ($blockers !== []) {
                    throw ValidationException::withMessages(['cart' => $blockers]);
                }

                $subtotal = $evaluated->subtotal;
                $application = $couponCode !== null && trim($couponCode) !== ''
                    ? $this->coupons->apply($couponCode, $user, $subtotal, lock: true)
                    : null;
                $discount = $application?->discount ?? Money::zero();
                $shipping = $this->shipping->cost($address->state, $subtotal->subtract($discount));

                $items = $this->orderItems($evaluated);
                $this->guardTotals($items, $subtotal);

                // Hold the stock. Throws (and rolls everything back) if any product cannot cover its units.
                $this->stock->reserve($this->demand($items));

                $order = Order::create([
                    'order_number' => $this->numbers->next(),
                    'user_id' => $user->id,
                    'status' => OrderStatus::PendingPayment,
                    'payment_status' => PaymentStatus::Pending,
                    'subtotal' => $subtotal,
                    'discount_total' => $discount,
                    'shipping_total' => $shipping,
                    'total' => $subtotal->subtract($discount)->add($shipping),
                    'currency' => Money::CURRENCY,
                    'coupon_id' => $application?->coupon->id,
                    'coupon_code' => $application?->coupon->code,
                    'shipping_name' => $address->recipient_name,
                    'shipping_phone' => $address->phone,
                    'shipping_line1' => $address->line1,
                    'shipping_line2' => $address->line2,
                    'shipping_city' => $address->city,
                    'shipping_state' => $address->state,
                    'shipping_postcode' => $address->postcode,
                    'shipping_country' => $address->country,
                    'notes' => $notes,
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => now()->addMinutes((int) config('shop.payment_window_minutes')),
                ]);

                foreach ($items as $item) {
                    $order->items()->create($item);
                }

                if ($application) {
                    CouponUsage::create([
                        'coupon_id' => $application->coupon->id,
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'discount_amount' => $discount,
                    ]);
                }

                $cart->items()->delete();

                $this->audit->log('order.created', $user, $order, null, [
                    'order_number' => $order->order_number,
                    'total' => $order->total->sen,
                    'items' => count($items),
                ]);

                return new PlacedOrder($order, true);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests raced past the lock (should not happen); hand back the order the winner created.
            if ($existing = $this->alreadyPlaced($user, $idempotencyKey)) {
                return new PlacedOrder($existing, false);
            }

            throw $e;
        }
    }

    private function alreadyPlaced(User $user, ?string $key): ?Order
    {
        return $key === null ? null : Order::where('user_id', $user->id)->where('idempotency_key', $key)->first();
    }

    /** @return array<int, string> */
    private function blockers(EvaluatedCart $cart, ?Address $address): array
    {
        if ($cart->lines === []) {
            return ['Your cart is empty.'];
        }

        $reasons = [];

        foreach ($cart->lines as $line) {
            foreach ($line->issues as $issue) {
                if ($issue->severity === BuildIssue::ERROR) {
                    $reasons[] = $issue->message;
                }
            }
        }

        if ($address === null) {
            $reasons[] = 'Choose a delivery address.';
        }

        return $reasons;
    }

    /**
     * One order row per product, priced at today's price. The parts of a PC share a build_group so they can still be
     * shown together. A part's quantity already includes the number of PCs ordered.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderItems(EvaluatedCart $cart): array
    {
        $items = [];

        foreach ($cart->lines as $line) {
            $item = $line->item;

            foreach ($line->parts as $part) {
                $quantity = $part->quantity * $item->quantity;

                $items[] = [
                    'product_id' => $part->product->id,
                    'sku' => $part->product->sku,
                    'name' => $part->product->name,
                    'quantity' => $quantity,
                    'unit_price' => $part->unitPrice,
                    'line_total' => $part->unitPrice->multiply($quantity),
                    'slot' => $part->slot,
                    'build_group' => $item->isBuild() ? "build-{$item->id}" : null,
                    'build_name' => $item->isBuild() ? $item->name : null,
                    'build_copies' => $item->isBuild() ? $item->quantity : null,
                ];
            }
        }

        return $items;
    }

    /** The order lines must add up to the subtotal the customer was shown. If they do not, something is badly wrong. */
    private function guardTotals(array $items, Money $subtotal): void
    {
        $sum = Money::zero();

        foreach ($items as $item) {
            $sum = $sum->add($item['line_total']);
        }

        if (! $sum->equals($subtotal)) {
            throw new LogicException("Order lines add up to {$sum->format()} but the cart subtotal is {$subtotal->format()}.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, int> product id => units
     */
    private function demand(array $items): array
    {
        $demand = [];

        foreach ($items as $item) {
            $demand[$item['product_id']] = ($demand[$item['product_id']] ?? 0) + $item['quantity'];
        }

        return $demand;
    }
}
