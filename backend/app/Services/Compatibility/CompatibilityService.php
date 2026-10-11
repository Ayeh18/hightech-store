<?php

namespace App\Services\Compatibility;

use App\Models\CompatibilityRule;

/**
 * Runs the compatibility rules. This is the single source of truth: the builder, the cart and checkout
 * all ask this class, so a build can never be "compatible" in one place and not in another.
 */
class CompatibilityService
{
    public function __construct(private readonly RuleRegistry $registry, private readonly PowerEstimator $power) {}

    /** Loads each rule's current settings (one query). Pass the result to the methods below when checking many builds. */
    public function rules(): ActiveRules
    {
        $rows = CompatibilityRule::all()->keyBy('key');
        $entries = [];

        foreach ($this->registry->all() as $key => $rule) {
            $row = $rows->get($key);
            $severity = $row?->severity;

            $entries[$key] = [
                'rule' => $rule,
                'settings' => new RuleSettings(
                    enabled: $row->is_enabled ?? true,
                    severity: in_array($severity, [CompatibilityIssue::ERROR, CompatibilityIssue::WARNING], true) ? $severity : $rule->defaultSeverity(),
                    params: array_merge($rule->defaultParams(), $row->params ?? []),
                ),
            ];
        }

        return new ActiveRules($entries);
    }

    /** Checks a whole build. */
    public function check(BuildContext $build, ?ActiveRules $rules = null): CompatibilityReport
    {
        $rules ??= $this->rules();
        $issues = [];

        foreach ($rules->enabled() as ['rule' => $rule, 'settings' => $settings]) {
            array_push($issues, ...$rule->evaluate($build, $settings));
        }

        return new CompatibilityReport($issues, $this->metrics($build, $rules));
    }

    /**
     * Would $candidate work next to the other parts? Only problems that involve the candidate's own slot are
     * reported, so an existing mismatch elsewhere in the build does not mark every candidate as incompatible.
     * Rules about EMPTY slots are skipped: a single part cannot be blamed for something not selected yet.
     */
    public function checkCandidate(BuildContext $others, Part $candidate, ActiveRules $rules): CompatibilityReport
    {
        $build = $others->with($candidate);
        $issues = [];

        foreach ($rules->enabled() as ['rule' => $rule, 'settings' => $settings]) {
            if ($rule->usesMissingParts()) {
                continue;
            }

            foreach ($rule->evaluate($build, $settings) as $issue) {
                if ($issue->involves($candidate->slot)) {
                    $issues[] = $issue;
                }
            }
        }

        return new CompatibilityReport($issues);
    }

    /** @return array{estimated_power_watts: ?int, recommended_psu_watts: ?int} */
    private function metrics(BuildContext $build, ActiveRules $rules): array
    {
        $base = (int) ($rules->settings('psu_covers_estimated_load')?->param('base_system_watts', 80) ?? 80);
        $headroom = (int) ($rules->settings('psu_has_headroom')?->param('headroom_percent', 30) ?? 30);
        $estimated = $this->power->estimateWatts($build, $base);

        return [
            'estimated_power_watts' => $estimated,
            'recommended_psu_watts' => $estimated === null ? null : $this->power->recommendedWatts($estimated, $headroom),
        ];
    }
}
