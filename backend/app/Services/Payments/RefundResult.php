<?php

namespace App\Services\Payments;

/** The gateway's answer to "please refund": its own id for the refund. A refusal is an exception instead. */
final readonly class RefundResult
{
    public function __construct(public string $providerReference) {}
}
