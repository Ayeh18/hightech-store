<?php

namespace App\Enums;

/** What a payment company says about a payment, in its own books. */
enum GatewayPaymentState: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'not paid yet',
            self::Succeeded => 'paid',
            self::Failed => 'failed',
            self::Cancelled => 'cancelled',
        };
    }
}
