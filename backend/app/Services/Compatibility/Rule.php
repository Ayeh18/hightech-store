<?php

namespace App\Services\Compatibility;

/**
 * One compatibility check, e.g. "the CPU socket must match the motherboard socket".
 *
 * To add a rule: write a class implementing this (usually by extending AbstractRule), list it in
 * config/compatibility.php, and run the CompatibilityRuleSeeder. Nothing else changes.
 */
interface Rule
{
    /** Stable identifier stored in compatibility_rules.key and shown to the API. */
    public function key(): string;

    public function name(): string;

    public function description(): string;

    /** 'error' blocks buying the build; 'warning' only informs. Admins can change it per rule. */
    public function defaultSeverity(): string;

    /** Tunable numbers and their defaults, e.g. ['base_system_watts' => 80]. */
    public function defaultParams(): array;

    /** Laravel validation rules for each tunable number, used when an admin edits them. */
    public function paramRules(): array;

    /**
     * The spec keys this rule reads, per slot: ['cpu' => ['socket'], 'motherboard' => ['socket']].
     * A test checks every key exists in the catalog's spec definitions, so renaming a spec cannot silently disable a rule.
     *
     * @return array<string, array<int, string>>
     */
    public function specs(): array;

    /** True when the rule fires because a slot is EMPTY (so it only makes sense for a whole build, not a single candidate part). */
    public function usesMissingParts(): bool;

    /**
     * Checks the build. Return [] when the build is fine OR when a needed part or spec is missing:
     * a rule only judges what it has data for.
     *
     * @return array<int, CompatibilityIssue>
     */
    public function evaluate(BuildContext $build, RuleSettings $settings): array;
}
