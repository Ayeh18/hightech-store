<?php

namespace App\Enums;

/** What happened to a webhook delivery. Every outcome is answered with HTTP 200 so the gateway stops retrying. */
enum WebhookOutcome: string
{
    case Processed = 'processed';
    case Duplicate = 'duplicate';            // this exact event was already handled: nothing was done again
    case Ignored = 'ignored';                // unknown payment, or an event that does not fit the payment's state
    case AmountMismatch = 'amount_mismatch';
    case LatePayment = 'late_payment';
}
