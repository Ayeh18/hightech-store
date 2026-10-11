<?php

use App\Enums\BuilderSlot;
use App\Enums\BuildPriority;
use App\Enums\BuildPurpose;
use App\Enums\TargetResolution;
use App\Models\CompatibilityRule;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityService;
use App\Services\PcBuilder\BuildEvaluator;
use App\Services\Recommendations\BuildBrief;
use App\Services\Recommendations\Candidates;
use App\Services\Recommendations\Exceptions\CannotRecommend;
use App\Services\Recommendations\ProposedBuild;
use App\Services\Recommendations\RuleBasedRecommender;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;

function brief(int $ringgit, BuildPurpose $purpose = BuildPurpose::Gaming, BuildPriority $priority = BuildPriority::Balanced, TargetResolution $resolution = TargetResolution::FullHd): BuildBrief
{
    return new BuildBrief(Money::fromSen($ringgit * 100), $purpose, $priority, $resolution);
}

function proposeFor(BuildBrief $brief): ProposedBuild
{
    return app(RuleBasedRecommender::class)->propose($brief, Candidates::load());
}

/** The names of the chosen products, keyed by slot. */
function chosen(ProposedBuild $proposal): array
{
    return collect($proposal->picks)->map(fn (array $pick) => Product::findOrFail($pick['product_id'])->name)->all();
}

function costOf(ProposedBuild $proposal): int
{
    return app(BuildEvaluator::class)->evaluateSelection($proposal->selection())->priced->subtotal->sen;
}

describe('with a small catalog', function () {
    beforeEach(fn () => $this->parts = miniCatalog());

    it('builds an office PC without a graphics card, on a processor that has graphics built in', function () {
        $proposal = proposeFor(brief(2500, BuildPurpose::Work));

        expect($proposal->picks)->not->toHaveKey('gpu')
            ->and(chosen($proposal)['cpu'])->toBe('Ryzen Six') // the cheaper Core Six F has no built-in graphics
            ->and(implode(' ', $proposal->notes))->toContain('No graphics card');
    });

    it('gives a gaming PC a graphics card and spends the largest share on it', function () {
        $proposal = proposeFor(brief(8000));
        $prices = collect($proposal->picks)->map(fn (array $pick) => Product::find($pick['product_id'])->effective_price->sen);

        expect($proposal->picks)->toHaveKey('gpu')
            ->and(chosen($proposal)['gpu'])->toBe('Card 16GB')
            ->and($prices['gpu'])->toBe($prices->max())
            ->and(chosen($proposal)['case'])->toBe('Case Big'); // the small case is too short for the 330 mm card
    });

    it('adds a cooler only when the processor does not come with one', function () {
        $boxed = proposeFor(brief(3000, BuildPurpose::Work));
        $bare = proposeFor(brief(8000, BuildPurpose::Creation)); // editing wants the twelve-core, which has no cooler

        expect(chosen($boxed)['cpu'])->toBe('Ryzen Six')
            ->and($boxed->picks)->not->toHaveKey('cooler')
            ->and(implode(' ', $boxed->notes))->toContain('comes with one in the box')
            ->and(chosen($bare)['cpu'])->toBe('Ryzen Twelve')
            ->and($bare->picks)->toHaveKey('cooler')
            ->and(chosen($bare)['cooler'])->toBe('Cooler 220W'); // the 95 W cooler is not rated for a 170 W processor
    });

    it('keeps every part on one platform: the memory and the board always match the processor', function (int $ringgit, BuildPurpose $purpose) {
        $names = chosen(proposeFor(brief($ringgit, $purpose)));
        $am5 = str_starts_with($names['cpu'], 'Ryzen');

        expect($names['motherboard'])->toBe($am5 ? 'Board AM5' : 'Board LGA')
            ->and($names['ram'])->toStartWith($am5 ? 'DDR5' : 'DDR4');
    })->with([[2600, BuildPurpose::Gaming], [4000, BuildPurpose::Gaming], [9000, BuildPurpose::Gaming], [2500, BuildPurpose::Work], [6000, BuildPurpose::Creation]]);

    it('chooses a power supply with room to spare, never merely the cheapest', function () {
        $proposal = proposeFor(brief(8000)); // a 300 W card whose maker recommends 750 W

        expect(chosen($proposal)['psu'])->toBe('Power 750W');
    });

    it('never goes over the budget, whatever is asked', function () {
        $over = [];

        foreach ([2300, 2600, 3000, 4000, 5500, 8000, 12000] as $ringgit) {
            foreach (BuildPurpose::cases() as $purpose) {
                foreach (BuildPriority::cases() as $priority) {
                    if (($cost = costOf(proposeFor(brief($ringgit, $purpose, $priority)))) > $ringgit * 100) {
                        $over[] = "RM {$ringgit} {$purpose->value} {$priority->value} cost ".Money::fromSen($cost)->format();
                    }
                }
            }
        }

        expect($over)->toBe([]);
    });

    it('spends less when asked to save money, and more on graphics when asked for performance', function () {
        $value = proposeFor(brief(6000, BuildPurpose::Gaming, BuildPriority::Value));
        $balanced = proposeFor(brief(6000, BuildPurpose::Gaming, BuildPriority::Balanced));

        expect(costOf($value))->toBeLessThanOrEqual(540000) // nine tenths of the budget
            ->and(costOf($value))->toBeLessThan(costOf($balanced));
    });

    it('falls back to built-in graphics when a gaming budget cannot include a card, and says so', function () {
        // The cheapest PC with a card and a power supply that has headroom is RM 3,080 (Core Six F, Board LGA, DDR4,
        // Card 8GB, SSD 500GB, Case Small, Power 750W). Without a card it is RM 2,300.
        $proposal = proposeFor(brief(2800));

        expect($proposal->picks)->not->toHaveKey('gpu')
            ->and(chosen($proposal)['cpu'])->toBe('Ryzen Six')
            ->and(implode(' ', $proposal->notes))->toContain('does not stretch to a graphics card');
    });

    it('says what the cheapest possible PC costs when the budget is below it', function () {
        try {
            proposeFor(brief(1500));
            $this->fail('Expected the budget to be refused.');
        } catch (CannotRecommend $e) {
            // Ryzen Six 800 + Board AM5 600 + DDR5 16GB 250 + SSD 500GB 170 + Case Big 300 (the board is ATX) + Power 450W 180.
            expect($e->forCustomer)->toBeTrue()->and($e->getMessage())->toContain('RM 2,300.00');
        }
    });

    it('gives the same answer every time for the same brief and catalog', function () {
        expect(proposeFor(brief(5000))->picks)->toBe(proposeFor(brief(5000))->picks);
    });

    it('never chooses a product that is out of stock or not on sale', function () {
        expect(chosen(proposeFor(brief(8000)))['gpu'])->toBe('Card 16GB');

        Inventory::where('product_id', $this->parts['gpu_big']->id)->update(['stock' => 0]);
        expect(chosen(proposeFor(brief(8000)))['gpu'])->toBe('Card 8GB');

        $this->parts['gpu_small']->update(['status' => 'draft']);
        expect(proposeFor(brief(8000))->picks)->not->toHaveKey('gpu'); // no card left: built-in graphics instead
    });

    it('prefers a graphics card with enough memory for a sharper screen', function () {
        // RM 5,200 at 1080p: the 8 GB card is enough, and the money goes elsewhere first.
        // At 4K the 16 GB card is preferred whenever it can be afforded at all.
        $fourK = proposeFor(brief(7000, BuildPurpose::Gaming, BuildPriority::Balanced, TargetResolution::UltraHd));

        expect(chosen($fourK)['gpu'])->toBe('Card 16GB');
    });

    it('explains every part it chose in a sentence', function () {
        $proposal = proposeFor(brief(8000));

        foreach ($proposal->picks as $slot => $pick) {
            expect($pick['reason'])->toBeString()->not->toBe('')->and(strlen($pick['reason']))->toBeLessThan(220);
        }

        expect($proposal->picks['psu']['reason'])->toContain('750 W')->toContain('room to spare')
            ->and($proposal->picks['gpu']['reason'])->toContain('16 GB')
            ->and($proposal->picks['storage']['reason'])->toMatch('/\d (TB|GB) NVMe SSD/');
    });

    it('respects a compatibility rule that an admin has switched off', function () {
        // With "the card must fit the case" off, the cheaper small case becomes acceptable for the long card.
        CompatibilityRule::updateOrCreate(['key' => 'gpu_fits_case'], ['is_enabled' => false, 'severity' => 'error', 'params' => []]);

        expect(chosen(proposeFor(brief(8000, BuildPurpose::Gaming, BuildPriority::Value)))['gpu'])->toBe('Card 16GB');
    });
});

describe('with the full demo catalog', function () {
    it('makes a complete, compatible PC within budget for every kind of brief, and quotes the true minimum', function () {
        $this->seed(CatalogSeeder::class);
        $candidates = Candidates::load();
        $recommender = app(RuleBasedRecommender::class);
        $evaluator = app(BuildEvaluator::class);
        $problems = [];

        foreach ([2000, 2500, 3200, 4000, 5500, 7000, 9000, 12000, 20000, 60000] as $ringgit) {
            foreach (BuildPurpose::cases() as $purpose) {
                foreach (BuildPriority::cases() as $priority) {
                    foreach ([TargetResolution::FullHd, TargetResolution::UltraHd] as $resolution) {
                        $name = "RM {$ringgit} {$purpose->value} {$priority->value} {$resolution->value}";
                        $proposal = $recommender->propose(brief($ringgit, $purpose, $priority, $resolution), $candidates);
                        $build = $evaluator->evaluateSelection($proposal->selection());
                        $rules = array_map(fn ($issue) => $issue->rule, $build->compatibility->issues);

                        if (! $build->priced->isComplete() || ! $build->isPurchasable()) {
                            $problems[] = "{$name}: not a complete, buyable PC";
                        }
                        if (array_intersect($rules, ['cpu_needs_graphics_card', 'cpu_cooler_required'])) {
                            $problems[] = "{$name}: unusable (no graphics or no cooler)";
                        }
                        if ($build->priced->subtotal->sen > $ringgit * 100) {
                            $problems[] = "{$name}: over budget at {$build->priced->subtotal->format()}";
                        }
                        if ($purpose !== BuildPurpose::Work && $ringgit >= 3200 && ! isset($proposal->picks['gpu'])) {
                            $problems[] = "{$name}: no graphics card";
                        }
                    }
                }
            }
        }

        expect($problems)->toBe([]);

        // The "cheapest possible PC" quoted to customers, against every processor with every motherboard.
        $compatibility = app(CompatibilityService::class);
        $rules = $compatibility->rules();
        $context = fn (array $picked) => BuildContext::of(array_map(fn ($c) => $c->part, $picked));
        $cheapestFit = function (BuilderSlot $slot, array $picked) use ($candidates, $compatibility, $rules, $context) {
            foreach ($candidates->for($slot) as $candidate) {
                if (! $compatibility->checkCandidate($context($picked), $candidate->part, $rules)->isBlocking()) {
                    return $candidate;
                }
            }

            return null;
        };
        $minimum = PHP_INT_MAX;

        foreach ($candidates->for(BuilderSlot::Cpu) as $cpu) {
            foreach ($candidates->for(BuilderSlot::Motherboard) as $board) {
                $picked = [$cpu, $board];
                $slots = array_filter([
                    BuilderSlot::Ram, BuilderSlot::Storage,
                    $cpu->part->text('integrated_graphics') === 'Yes' ? null : BuilderSlot::Gpu,
                    BuilderSlot::Case_,
                    $cpu->part->text('cooler_included') === 'Yes' ? null : BuilderSlot::Cooler,
                    BuilderSlot::Psu,
                ]);

                foreach ($slots as $slot) {
                    if (($fit = $cheapestFit($slot, $picked)) === null) {
                        continue 2;
                    }
                    $picked[] = $fit;
                }

                if (! $compatibility->check($context($picked), $rules)->isBlocking()) {
                    $minimum = min($minimum, array_sum(array_map(fn ($c) => $c->priceSen, $picked)));
                }
            }
        }

        $quoted = array_sum(array_map(fn ($c) => $c->priceSen, $recommender->cheapest($candidates)));

        expect($quoted)->toBe($minimum);
        expect(fn () => $recommender->propose(brief(intdiv($minimum, 100) - 1), $candidates))->toThrow(CannotRecommend::class, Money::fromSen($minimum)->format());
    });
});
