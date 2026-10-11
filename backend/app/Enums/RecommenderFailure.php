<?php

namespace App\Enums;

/**
 * Why a recommender's build could not be used, as one of a few fixed kinds. The full sentence (which product, which
 * amount) is kept beside it for whoever investigates; the kind is what the shop's insights count, because "over budget
 * by RM 4,623" and "over budget by RM 5,100" are the same reason.
 */
enum RecommenderFailure: string
{
    case NotSetUp = 'not_set_up';
    case Unreachable = 'unreachable';
    case ServiceError = 'service_error';
    case AllowanceUsed = 'allowance_used';
    case Unreadable = 'unreadable';
    case NotOffered = 'not_offered';
    case Incomplete = 'incomplete';
    case Incompatible = 'incompatible';
    case Unusable = 'unusable';
    case OverBudget = 'over_budget';
    case BudgetTooLow = 'budget_too_low';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotSetUp => 'The AI service was not set up',
            self::Unreachable => 'The AI service could not be reached',
            self::ServiceError => 'The AI service answered with an error',
            self::AllowanceUsed => "The day's allowance of AI requests was used up",
            self::Unreadable => 'Its answer could not be read',
            self::NotOffered => 'It chose a product it was not offered',
            self::Incomplete => 'It left out a part every PC needs',
            self::Incompatible => 'Its parts did not work together',
            self::Unusable => 'Its PC could not have been used',
            self::OverBudget => 'Its build cost more than the budget',
            self::BudgetTooLow => 'The budget was below the cheapest PC',
            self::Other => 'Another reason',
        };
    }
}
