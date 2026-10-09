<?php

namespace App\Enums;

/** The kinds of entry in a payment's diary (payment_transactions). */
enum PaymentTransactionType: string
{
    case Initiated = 'INITIATED';            // we asked the gateway for a payment page
    case Processing = 'PROCESSING';          // the gateway is still working on it (e.g. a bank transfer)
    case Succeeded = 'SUCCEEDED';            // the money arrived
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';            // the customer walked away, or the order was cancelled
    case AmountMismatch = 'AMOUNT_MISMATCH'; // the gateway reports a different amount than we asked for: NOT accepted
    case LateSuccess = 'LATE_SUCCESS';       // money arrived for an order that can no longer be fulfilled: needs a refund
    case Ignored = 'IGNORED';                // an event that makes no sense now (e.g. "failed" after "succeeded")
}
