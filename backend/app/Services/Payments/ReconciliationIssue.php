<?php

namespace App\Services\Payments;

/** One way our books and the payment company's books disagree about a payment. */
final readonly class ReconciliationIssue
{
    /**
     * @param  string  $code  stable name for tests and screens
     * @param  string  $message  what is wrong, in plain words
     * @param  bool  $canSync  true when asking the gateway again (the same safe way a webhook is handled) can put it right
     */
    public function __construct(public string $code, public string $message, public bool $canSync = false) {}
}
