<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/** The webhook is not trustworthy (bad or missing signature, too old) or not understandable. It is never processed. */
class InvalidWebhook extends RuntimeException
{
    public function __construct(string $message, public readonly bool $forged = true)
    {
        parent::__construct($message);
    }

    public static function badSignature(string $why): self
    {
        return new self("Webhook signature rejected: {$why}");
    }

    /** Signed correctly but the content is not what we expect. Not an attack, just unusable. */
    public static function malformed(string $why): self
    {
        return new self("Webhook could not be understood: {$why}", forged: false);
    }
}
