<?php

namespace App\Services\Recommendations\Exceptions;

use RuntimeException;

/** A recommender could not produce a build. `forCustomer` is true when the reason is theirs to act on (budget too low). */
class CannotRecommend extends RuntimeException
{
    public function __construct(string $message, public readonly bool $forCustomer = false)
    {
        parent::__construct($message);
    }

    public static function budgetTooLow(string $message): self
    {
        return new self($message, forCustomer: true);
    }
}
