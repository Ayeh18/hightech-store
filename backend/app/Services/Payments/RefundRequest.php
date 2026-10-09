<?php

namespace App\Services\Payments;

use App\Support\Money;

/** What we ask a gateway to give back. The idempotency key is our refund's id: sending it twice must never refund twice. */
final readonly class RefundRequest
{
    public function __construct(
        public string $paymentReference, // the gateway's id for the payment being refunded
        public Money $amount,
        public string $idempotencyKey,
        public string $reason,
    ) {}
}
