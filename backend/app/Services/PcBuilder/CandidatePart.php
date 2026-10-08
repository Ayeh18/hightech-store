<?php

namespace App\Services\PcBuilder;

use App\Models\Product;
use App\Services\Compatibility\CompatibilityReport;

/** A product offered for a slot, with how well it fits the rest of the build. */
final readonly class CandidatePart
{
    public function __construct(public Product $product, public CompatibilityReport $report) {}
}
