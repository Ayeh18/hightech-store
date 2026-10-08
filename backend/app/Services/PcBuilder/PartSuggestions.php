<?php

namespace App\Services\PcBuilder;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One page of CandidateParts plus summary numbers for the UI.
 *
 * @property LengthAwarePaginator<int, CandidatePart> $page
 */
final readonly class PartSuggestions
{
    /** @param  array<string, int>  $counts  how many candidates are compatible / warning / incompatible */
    public function __construct(public LengthAwarePaginator $page, public int $hiddenIncompatible, public array $counts) {}
}
