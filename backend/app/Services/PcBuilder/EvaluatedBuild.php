<?php

namespace App\Services\PcBuilder;

use App\Services\Compatibility\CompatibilityReport;

/** A build priced from the database AND checked for compatibility: everything the cart and checkout need to know. */
final readonly class EvaluatedBuild
{
    public function __construct(public PricedBuild $priced, public CompatibilityReport $compatibility) {}

    /**
     * Can this build be bought? It must be complete, every part on sale and in stock, and free of
     * compatibility ERRORS. Warnings (e.g. little PSU headroom) never block a purchase.
     */
    public function isPurchasable(): bool
    {
        return $this->priced->isPurchasable() && ! $this->compatibility->isBlocking();
    }
}
