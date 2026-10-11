<?php

namespace App\Services\Recommendations\Exceptions;

use App\Enums\RecommenderFailure;
use RuntimeException;

/**
 * A recommender could not produce a build. The message is the full reason, for the shop's records; `failure` is the kind
 * of reason, for counting; `forCustomer` is true when the reason is the customer's to act on (budget too low).
 */
class CannotRecommend extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly RecommenderFailure $failure = RecommenderFailure::Other,
        public readonly bool $forCustomer = false,
    ) {
        parent::__construct($message);
    }

    public static function budgetTooLow(string $message): self
    {
        return new self($message, RecommenderFailure::BudgetTooLow, forCustomer: true);
    }
}
