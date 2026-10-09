<?php

namespace App\Enums;

/** What is happening to the MONEY for an order. Independent of the order's own status. */
enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Refunded = 'REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Payment failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }
}
