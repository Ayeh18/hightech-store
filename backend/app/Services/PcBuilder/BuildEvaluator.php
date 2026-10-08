<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuild;
use App\Services\Compatibility\ActiveRules;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityService;
use App\Services\Compatibility\Part;

/** Prices a build and checks its compatibility in one step. Use this wherever a build's status matters. */
class BuildEvaluator
{
    public function __construct(private readonly BuildPricer $pricer, private readonly CompatibilityService $compatibility) {}

    /**
     * @param  array<int, array{slot: string, product_id: int, quantity?: int}>  $selection
     */
    public function evaluateSelection(array $selection): EvaluatedBuild
    {
        return $this->combine($this->pricer->priceSelection($selection));
    }

    /** Needs items.product.{category, inventory, specifications} eager loaded. */
    public function evaluateBuild(PcBuild $build, ?ActiveRules $rules = null): EvaluatedBuild
    {
        return $this->combine($this->pricer->priceBuild($build), $rules);
    }

    /** Rule settings for evaluating many builds with a single query. */
    public function rules(): ActiveRules
    {
        return $this->compatibility->rules();
    }

    private function combine(PricedBuild $priced, ?ActiveRules $rules = null): EvaluatedBuild
    {
        $parts = [];

        foreach ($priced->lines as $line) {
            // A product that must not be revealed (not on sale, guest request) has nothing to check.
            if ($line->product !== null) {
                $parts[] = Part::fromProduct($line->slot, $line->product, $line->quantity);
            }
        }

        return new EvaluatedBuild($priced, $this->compatibility->check(BuildContext::of($parts), $rules));
    }
}
