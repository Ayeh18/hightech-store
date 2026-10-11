<?php

namespace App\Services\Recommendations;

use App\Models\BuildRecommendation;
use App\Services\PcBuilder\EvaluatedBuild;

/** A suggestion that passed every check and is ready to show: the build as the shop prices it, and why each part. */
final readonly class Recommendation
{
    /**
     * @param  array<string, string>  $reasons  slot value => why this part
     * @param  list<string>  $notes
     */
    public function __construct(
        public BuildRecommendation $record,
        public BuildBrief $brief,
        public EvaluatedBuild $build,
        public array $reasons,
        public array $notes,
    ) {}
}
