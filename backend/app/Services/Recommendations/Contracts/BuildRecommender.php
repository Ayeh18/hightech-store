<?php

namespace App\Services\Recommendations\Contracts;

use App\Services\Recommendations\BuildBrief;
use App\Services\Recommendations\Candidates;
use App\Services\Recommendations\Exceptions\CannotRecommend;
use App\Services\Recommendations\ProposedBuild;

/**
 * Something that can propose a PC for a brief, choosing only from the candidates it is given. The built-in one follows
 * fixed rules; another asks an AI service. Neither is trusted: whatever comes back is validated by the caller.
 */
interface BuildRecommender
{
    /** A short name for records and for telling the customer where a suggestion came from: "rules", "ai". */
    public function name(): string;

    /** @throws CannotRecommend when it has no usable proposal (budget too small, service unavailable...) */
    public function propose(BuildBrief $brief, Candidates $candidates): ProposedBuild;
}
