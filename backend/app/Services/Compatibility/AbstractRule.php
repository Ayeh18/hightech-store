<?php

namespace App\Services\Compatibility;

use App\Enums\BuilderSlot;

/** Shared plumbing for rules. A concrete rule only describes itself and implements evaluate(). */
abstract class AbstractRule implements Rule
{
    public function defaultSeverity(): string
    {
        return CompatibilityIssue::ERROR;
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function paramRules(): array
    {
        return [];
    }

    public function usesMissingParts(): bool
    {
        return false;
    }

    /** Builds an issue with this rule's key and the severity currently configured for it. */
    protected function issue(RuleSettings $settings, string $message, BuilderSlot ...$slots): CompatibilityIssue
    {
        return new CompatibilityIssue($this->key(), $settings->severity, $message, array_values($slots));
    }
}
