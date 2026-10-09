<?php

namespace App\Services\Payments;

use App\Enums\GatewayEventType;
use App\Support\Money;

/** One thing a gateway told us about one payment, in our own vocabulary. */
final readonly class GatewayEvent
{
    /**
     * @param  string  $id  the gateway's unique id for this event (the idempotency key)
     * @param  array<string, mixed>  $payload  the original data, kept for the record
     */
    public function __construct(
        public string $id,
        public GatewayEventType $type,
        public string $providerReference,
        public ?Money $amount = null,
        public ?string $method = null,
        public ?string $failureReason = null,
        public array $payload = [],
    ) {}
}
