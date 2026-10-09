<?php

namespace App\Services\Payments;

use App\Services\Payments\Exceptions\InvalidWebhook;

/**
 * Signs and checks webhooks with a secret that only we and the gateway know.
 *
 * The signature is HMAC-SHA256 over "{timestamp}.{body}". Why the timestamp too? Without it, someone who captured one
 * genuine "payment succeeded" request could send it again next week. With it, requests older than the tolerance are refused.
 * The header looks like:  t=1760000000,v1=9f2c...
 */
class WebhookSigner
{
    public function sign(string $body, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /** @throws InvalidWebhook */
    public function verify(string $body, ?string $header, string $secret, int $toleranceSeconds, ?int $now = null): void
    {
        if ($secret === '') {
            throw InvalidWebhook::badSignature('no secret is configured');
        }

        if ($header === null || ! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
            throw InvalidWebhook::badSignature('missing or malformed header');
        }

        [, $timestamp, $given] = $m;

        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            throw InvalidWebhook::badSignature('timestamp outside the allowed window');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        // hash_equals compares in constant time, so response timing cannot be used to guess the signature byte by byte.
        if (! hash_equals($expected, $given)) {
            throw InvalidWebhook::badSignature('signature does not match');
        }
    }
}
