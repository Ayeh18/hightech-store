<?php

namespace App\Services\Compatibility;

/** The registered rules together with their current settings (from the database, or the defaults). */
final readonly class ActiveRules
{
    /** @param  array<string, array{rule: Rule, settings: RuleSettings}>  $entries */
    public function __construct(private array $entries) {}

    /** @return array<string, array{rule: Rule, settings: RuleSettings}> every registered rule, on or off */
    public function all(): array
    {
        return $this->entries;
    }

    /** @return array<string, array{rule: Rule, settings: RuleSettings}> only the rules that are switched on */
    public function enabled(): array
    {
        return array_filter($this->entries, fn (array $entry) => $entry['settings']->enabled);
    }

    /** Settings of one rule even when it is switched off (used for the power numbers). */
    public function settings(string $key): ?RuleSettings
    {
        return $this->entries[$key]['settings'] ?? null;
    }
}
