<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Requested = 'REQUESTED';   // the customer asked; waiting for staff
    case Rejected = 'REJECTED';     // staff said no
    case Processing = 'PROCESSING'; // approved, being sent to the payment company
    case Succeeded = 'SUCCEEDED';   // the money is on its way back to the customer
    case Failed = 'FAILED';         // the payment company refused or could not be reached; can be retried

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Waiting for review',
            self::Rejected => 'Declined',
            self::Processing => 'Refunding',
            self::Succeeded => 'Refunded',
            self::Failed => 'Refund problem',
        };
    }

    /** Counts against the payment's refundable amount: money that has been, or is about to be, given back. */
    public function holdsMoney(): bool
    {
        return in_array($this, [self::Processing, self::Succeeded], true);
    }
}
