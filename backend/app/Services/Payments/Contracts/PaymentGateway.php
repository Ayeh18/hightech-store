<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Exceptions\InvalidWebhook;
use App\Services\Payments\Exceptions\RefundRefused;
use App\Services\Payments\GatewayEvent;
use App\Services\Payments\GatewayTransaction;
use App\Services\Payments\PaymentRequest;
use App\Services\Payments\PaymentSession;
use App\Services\Payments\RefundRequest;
use App\Services\Payments\RefundResult;

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

    /**
     * Gives money back to the customer. MUST be idempotent on `$request->idempotencyKey`: asking again with the same key
     * returns the first answer instead of refunding a second time (a retry after a timeout is therefore always safe).
     *
     * @throws RefundRefused when the gateway refuses
     */
    public function refundPayment(RefundRequest $request): RefundResult;

    /**
     * The payment company's own record of a payment (its amount, state and refunds), for reconciliation: comparing their
     * books with ours. Null when the company has no such payment.
     */
    public function fetchTransaction(string $providerReference): ?GatewayTransaction;
}
