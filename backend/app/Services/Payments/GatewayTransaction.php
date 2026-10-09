<?php

namespace App\Services\Payments;

use App\Enums\GatewayPaymentState;
use App\Support\Money;

/**
 * The payment company's own record of one payment: the other side of the books. Used only to compare with ours.
 * `amount` is what the company holds: what was actually paid once it succeeded, otherwise what was asked for.
 */
final readonly class GatewayTransaction
{
    public function __construct(
        public string $reference,
        public GatewayPaymentState $state,
        public Money $amount,
        public Money $refunded,
    ) {}
}
