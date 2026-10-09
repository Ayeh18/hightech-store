<?php

namespace App\Enums;

/**
 * Where the ORDER is in its life: from waiting for payment to delivered. Whether the money has arrived is a
 * separate thing (PaymentStatus), so the two can never be confused.
 */
enum OrderStatus: string
{
    case PendingPayment = 'PENDING_PAYMENT';
    case PaymentProcessing = 'PAYMENT_PROCESSING';
    case Paid = 'PAID';
    case Processing = 'PROCESSING';
    case Packed = 'PACKED';
    case Shipped = 'SHIPPED';
    case Delivered = 'DELIVERED';
    case Cancelled = 'CANCELLED';
    case RefundRequested = 'REFUND_REQUESTED';
    case Refunded = 'REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Waiting for payment',
            self::PaymentProcessing => 'Payment processing',
            self::Paid => 'Paid',
            self::Processing => 'Being prepared',
            self::Packed => 'Packed',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::RefundRequested => 'Refund requested',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * The only moves an order may make. Anything else is a bug and must be refused, which is what keeps the
     * lifecycle trustworthy (a cancelled order can never become shipped, a delivered one can never become unpaid).
     *
     * @return array<int, self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::PendingPayment => [self::PaymentProcessing, self::Paid, self::Cancelled],
            self::PaymentProcessing => [self::Paid, self::PendingPayment, self::Cancelled],
            self::Paid => [self::Processing, self::RefundRequested, self::Cancelled],
            self::Processing => [self::Packed, self::RefundRequested, self::Cancelled],
            self::Packed => [self::Shipped, self::RefundRequested, self::Cancelled],
            self::Shipped => [self::Delivered, self::RefundRequested],
            self::Delivered => [self::RefundRequested],
            self::RefundRequested => [self::Refunded, self::Paid, self::Processing, self::Packed, self::Shipped, self::Delivered],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->nextStatuses(), true);
    }

    /** The customer may still cancel it themselves (nothing has been paid or shipped). */
    public function isCancellableByCustomer(): bool
    {
        return $this === self::PendingPayment;
    }
}
