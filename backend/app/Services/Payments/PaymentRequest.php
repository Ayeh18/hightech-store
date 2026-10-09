<?php

namespace App\Services\Payments;

use App\Support\Money;
use Carbon\CarbonInterface;

/** What we ask a gateway to charge for. The amount comes from the order total on the server. */
final readonly class PaymentRequest
{
    public function __construct(
        public string $merchantReference,   // our payments.id, so the gateway's records can be matched to ours
        public string $orderNumber,
        public Money $amount,
        public string $returnUrl,           // where the customer is sent back after the payment page
        public CarbonInterface $expiresAt,
        public string $description,
    ) {}
}
