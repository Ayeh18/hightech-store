<?php

namespace App\Services\Payments;

/** The gateway's answer to "please create a payment": its id for it, and where the customer goes to pay. */
final readonly class PaymentSession
{
    public function __construct(public string $providerReference, public string $checkoutUrl) {}
}
