<?php

namespace App\Services\Compatibility;

/** The outcome of checking a build: every issue found, plus a few numbers worth showing (power draw). */
final readonly class CompatibilityReport
{
    public const COMPATIBLE = 'compatible';

    public const WARNING = 'warning';

    public const INCOMPATIBLE = 'incompatible';

    /**
     * @param  array<int, CompatibilityIssue>  $issues
     * @param  array{estimated_power_watts: ?int, recommended_psu_watts: ?int}  $metrics
     */
    public function __construct(
        public array $issues,
        public array $metrics = ['estimated_power_watts' => null, 'recommended_psu_watts' => null],
    ) {}

    /** incompatible: at least one error. warning: only warnings. compatible: nothing found. */
    public function status(): string
    {
        $status = self::COMPATIBLE;

        foreach ($this->issues as $issue) {
            if ($issue->severity === CompatibilityIssue::ERROR) {
                return self::INCOMPATIBLE;
            }

            $status = self::WARNING;
        }

        return $status;
    }

    public function isBlocking(): bool
    {
        return $this->status() === self::INCOMPATIBLE;
    }
}
