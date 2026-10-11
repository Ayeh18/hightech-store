<?php

namespace App\Services\Recommendations;

use App\Enums\BuildPriority;
use App\Enums\BuildPurpose;
use App\Enums\TargetResolution;
use App\Support\Money;

/** What the customer asked for: how much they can spend and what the PC is for. */
final readonly class BuildBrief
{
    /** @param  list<string>  $games  titles the customer named; only an AI recommender reads them */
    public function __construct(
        public Money $budget,
        public BuildPurpose $purpose,
        public BuildPriority $priority = BuildPriority::Balanced,
        public TargetResolution $resolution = TargetResolution::FullHd,
        public array $games = [],
    ) {}

    /** The amount a build should aim for. "Save money" deliberately leaves a tenth of the budget unspent. */
    public function targetSen(): int
    {
        return $this->priority === BuildPriority::Value ? intdiv($this->budget->sen * 9, 10) : $this->budget->sen;
    }
}
