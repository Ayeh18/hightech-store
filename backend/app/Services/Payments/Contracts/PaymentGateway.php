<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Exceptions\InvalidWebhook;
use App\Services\Payments\GatewayEvent;
use App\Services\Payments\PaymentRequest;
use App\Services\Payments\PaymentSession;

/**
 * Everything the shop needs from a payment company. Each company (a real one, or our local simulator) gets one class
 * that implements this. The rest of the code never knows which company it is talking to.
 */
interface PaymentGateway
{
    /** The short name stored on payments and used in the webhook URL (/api/webhooks/{name}). */
    public function name(): string;

    /**
     * Asks the gateway for a payment page for this order. Returns the gateway's id for the payment and the address
     * the customer must be sent to in order to pay.
     */
    public function createPayment(PaymentRequest $request): PaymentSession;

    /**
     * Turns an incoming webhook into a GatewayEvent, AFTER checking it really came from the gateway (its signature).
     *
     * @param  string  $payload  the raw request body, exactly as received (the signature covers these exact bytes)
     * @param  array<string, string>  $headers  request headers, names in lower case
     *
     * @throws InvalidWebhook when the signature is wrong or too old, or the body cannot be understood
     */
    public function parseWebhook(string $payload, array $headers): GatewayEvent;

    /**
     * Asks the gateway what it knows about a payment right now. Used to catch up when a webhook never arrived.
     * Returns null while the payment is still undecided.
     */
    public function fetchStatus(string $providerReference): ?GatewayEvent;
}
