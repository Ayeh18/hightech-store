<?php

namespace App\Services\Recommendations;

use App\Enums\BuilderSlot;
use App\Enums\BuildPriority;
use App\Enums\BuildPurpose;
use App\Services\Compatibility\ActiveRules;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityReport;
use App\Services\Compatibility\CompatibilityService;
use App\Services\Recommendations\Contracts\BuildRecommender;
use App\Services\Recommendations\Exceptions\CannotRecommend;
use App\Support\Money;

/**
 * Proposes a PC by fixed rules, the way a careful shop assistant would:
 *
 *  1. Split the budget between the slots according to what the PC is for (a gaming PC spends most on graphics).
 *  2. Fill the slots in an order where each part must fit the ones already chosen, taking the best part that its
 *     share of the budget allows. "Fits" is decided by the shop's own compatibility rules, never by this class.
 *  3. If the result costs too much, step the least important parts down until it fits. If money is left over, step
 *     the most important parts up.
 *
 * The same catalog and the same brief always give the same build. It needs no outside service, so it is also what the
 * shop falls back on whenever an AI recommender is unavailable or proposes something unusable.
 *
 * "Best" within a slot is judged from the specifications that matter for the purpose where the catalog has them (threads
 * for video editing, memory size, drive capacity and speed), and otherwise by price among the parts that meet the
 * purpose's needs. The catalog has no benchmark scores, and within one catalog a dearer part of the same kind is, almost
 * always, the more capable one.
 */
class RuleBasedRecommender implements BuildRecommender
{
    /** Percent of the budget for each slot. Every row adds up to 100. */
    private const SHARES = [
        'gaming' => ['cpu' => 20, 'motherboard' => 11, 'ram' => 8, 'gpu' => 38, 'storage' => 8, 'case' => 6, 'cooler' => 3, 'psu' => 6],
        'creation' => ['cpu' => 26, 'motherboard' => 12, 'ram' => 12, 'gpu' => 26, 'storage' => 10, 'case' => 5, 'cooler' => 3, 'psu' => 6],
        'work' => ['cpu' => 30, 'motherboard' => 18, 'ram' => 14, 'gpu' => 0, 'storage' => 16, 'case' => 11, 'cooler' => 3, 'psu' => 8],
    ];

    /** Which slots to improve first when money is left over. Stepping down goes through the same list backwards. */
    private const IMPORTANCE = [
        'gaming' => ['gpu', 'cpu', 'ram', 'storage', 'motherboard', 'case'],
        'creation' => ['cpu', 'gpu', 'ram', 'storage', 'motherboard', 'case'],
        'work' => ['cpu', 'storage', 'ram', 'motherboard', 'case'],
    ];

    public function __construct(private readonly CompatibilityService $compatibility) {}

    public function name(): string
    {
        return 'rules';
    }

    public function propose(BuildBrief $brief, Candidates $candidates): ProposedBuild
    {
        $rules = $this->compatibility->rules();
        $notes = [];

        // An office PC runs on the processor's built-in graphics. A gaming or editing PC gets a graphics card, unless
        // the budget cannot stretch to one, in which case built-in graphics is the honest second-best.
        $wantsCard = $brief->purpose !== BuildPurpose::Work;
        $picks = $wantsCard ? $this->assemble($brief, $candidates, $rules, graphicsCard: true) : null;

        if ($picks === null) {
            $picks = $this->assemble($brief, $candidates, $rules, graphicsCard: false);

            if ($picks !== null && $wantsCard) {
                $notes[] = 'This budget does not stretch to a graphics card, so the build uses the processor\'s built-in graphics. It runs light games; a graphics card can be added later.';
            }
        }

        if ($picks === null) {
            throw CannotRecommend::budgetTooLow($this->tooLowMessage($candidates, $rules));
        }

        return $this->describe($picks, $brief, $notes, $rules);
    }

    /**
     * The lowest-priced complete, compatible PC this recommender can find in the catalog, or null when it cannot make
     * one at all. (Found by starting from the cheapest parts and stepping down; a rare cheaper combination may exist.)
     *
     * @return array<string, Candidate>|null
     */
    public function cheapest(Candidates $candidates, ?ActiveRules $rules = null): ?array
    {
        $rules ??= $this->compatibility->rules();
        $found = array_filter([
            $this->floor($candidates, $rules, graphicsCard: false),
            $this->floor($candidates, $rules, graphicsCard: true),
        ]);

        usort($found, fn (array $a, array $b) => $this->total($a) <=> $this->total($b));

        return $found[0] ?? null;
    }

    /**
     * @return array<string, Candidate>|null picks within the budget, or null when this kind of build cannot fit it
     */
    private function assemble(BuildBrief $brief, Candidates $candidates, ActiveRules $rules, bool $graphicsCard): ?array
    {
        $picks = $this->firstPass($brief, $candidates, $rules, $graphicsCard, $brief->targetSen());
        $picks = $picks === null ? null : $this->stepDown($picks, $brief, $candidates, $rules, $graphicsCard);

        // Stepping down changes one part at a time, so it cannot move to a cheaper family of parts (another socket,
        // older memory). When it gets stuck above the budget, start again from the cheapest build and work upwards.
        if ($picks === null || $this->total($picks) > $brief->budget->sen) {
            $picks = $this->floor($candidates, $rules, $graphicsCard);
        }

        // "Save money" aims below the budget but may use all of it; nothing may ever exceed it.
        if ($picks === null || $this->total($picks) > $brief->budget->sen) {
            return null;
        }

        return $this->stepUp($picks, $brief, $candidates, $rules, $graphicsCard);
    }

    /**
     * The cheapest build of one kind (with or without a graphics card).
     *
     * @return array<string, Candidate>|null
     */
    private function floor(Candidates $candidates, ActiveRules $rules, bool $graphicsCard): ?array
    {
        $nothing = new BuildBrief(Money::fromSen(0), BuildPurpose::Work);
        $picks = $this->firstPass($nothing, $candidates, $rules, $graphicsCard, budget: 0);

        return $picks === null ? null : $this->stepDown($picks, $nothing, $candidates, $rules, $graphicsCard);
    }

    /**
     * One part per slot, each the best its share of the budget allows among those that fit the parts before it.
     *
     * @return array<string, Candidate>|null
     */
    private function firstPass(BuildBrief $brief, Candidates $candidates, ActiveRules $rules, bool $graphicsCard, int $budget): ?array
    {
        $shares = $this->shares($brief, $graphicsCard);
        $slots = [BuilderSlot::Cpu, BuilderSlot::Motherboard, BuilderSlot::Ram, BuilderSlot::Storage, BuilderSlot::Case_];

        if ($graphicsCard) {
            array_splice($slots, 3, 0, [BuilderSlot::Gpu]); // before the case, which has to be long enough for it
        }

        $picks = [];

        foreach ($slots as $slot) {
            $options = $this->optionsFor($slot, $picks, $candidates, $rules, $graphicsCard);

            if ($options === []) {
                return null;
            }

            $picks[$slot->value] = $this->choose($slot, $options, intdiv($budget * $shares[$slot->value], 100), $brief);
        }

        return $this->complete($picks, $candidates, $rules, $this->coolerAllowance($brief));
    }

    /** What the build may spend on a separate cooler: its share of the amount aimed for. */
    private function coolerAllowance(BuildBrief $brief): int
    {
        return intdiv($brief->targetSen() * self::SHARES[$brief->purpose->value]['cooler'], 100);
    }

    /**
     * Replace the least important parts with cheaper ones, one step at a time, until the build fits.
     *
     * @param  array<string, Candidate>  $picks
     * @return array<string, Candidate>
     */
    private function stepDown(array $picks, BuildBrief $brief, Candidates $candidates, ActiveRules $rules, bool $graphicsCard): array
    {
        // Least important first. The graphics card is not in every purpose's list, so it is added for the cases where
        // a build has one anyway.
        $order = array_unique([...array_reverse(self::IMPORTANCE[$brief->purpose->value]), BuilderSlot::Gpu->value]);
        $limit = $brief->targetSen();
        $coolerAllowance = $this->coolerAllowance($brief);

        // A cooler above the cheapest adequate one is a nicety: it is the first thing to go when money is short.
        if ($this->total($picks) > $limit && ($plain = $this->complete($picks, $candidates, $rules)) !== null) {
            $picks = $plain;
            $coolerAllowance = 0;
        }

        while ($this->total($picks) > $limit) {
            $changed = false;

            foreach ($order as $slotValue) {
                if (! isset($picks[$slotValue])) {
                    continue;
                }

                $slot = BuilderSlot::from($slotValue);
                $cheaper = array_reverse(array_filter(
                    $this->optionsFor($slot, [], $candidates, $rules, $graphicsCard),
                    fn (Candidate $c) => $c->priceSen < $picks[$slotValue]->priceSen,
                ));

                foreach ($cheaper as $candidate) { // the next one down first
                    $trial = $this->complete([...$picks, $slotValue => $candidate], $candidates, $rules, $coolerAllowance);

                    if ($trial !== null && $this->total($trial) < $this->total($picks)) {
                        $picks = $trial;
                        $changed = true;

                        break;
                    }
                }

                if ($changed) {
                    break; // look at the total again before touching a more important part
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $picks;
    }

    /**
     * Spend what is left on the parts that matter most for this purpose.
     *
     * @param  array<string, Candidate>  $picks
     * @return array<string, Candidate>
     */
    private function stepUp(array $picks, BuildBrief $brief, Candidates $candidates, ActiveRules $rules, bool $graphicsCard): array
    {
        $limit = max($brief->targetSen(), $this->total($picks));

        do {
            $changed = false;

            foreach (self::IMPORTANCE[$brief->purpose->value] as $slotValue) {
                if (! isset($picks[$slotValue])) {
                    continue;
                }

                $slot = BuilderSlot::from($slotValue);
                $current = $this->rank($slot, $picks[$slotValue], $brief);
                $better = array_filter(
                    $this->preferred($slot, $this->optionsFor($slot, [], $candidates, $rules, $graphicsCard), $brief),
                    fn (Candidate $c) => $this->rank($slot, $c, $brief) > $current,
                );
                usort($better, fn (Candidate $a, Candidate $b) => $this->rank($slot, $b, $brief) <=> $this->rank($slot, $a, $brief));

                foreach ($better as $candidate) { // the best one that still fits the budget
                    $trial = $this->complete([...$picks, $slotValue => $candidate], $candidates, $rules, $this->coolerAllowance($brief));

                    if ($trial !== null && $this->total($trial) <= $limit) {
                        $picks = $trial;
                        $changed = true;

                        break;
                    }
                }
            }
        } while ($changed);

        return $picks;
    }

    /**
     * Makes a set of main parts into a whole PC: adds (or drops) the separate cooler depending on the processor, then
     * the smallest power supply that is comfortably enough, and confirms that everything fits together.
     *
     * @param  array<string, Candidate>  $picks
     * @return array<string, Candidate>|null null when no cooler or power supply fits, or the parts clash
     */
    private function complete(array $picks, Candidates $candidates, ActiveRules $rules, int $coolerAllowance = 0): ?array
    {
        unset($picks[BuilderSlot::Cooler->value], $picks[BuilderSlot::Psu->value]);

        if ($picks[BuilderSlot::Cpu->value]->part->text('cooler_included') !== 'Yes') {
            $cooler = $this->cooler($picks, $candidates, $rules, $coolerAllowance);

            if ($cooler === null) {
                return null;
            }

            $picks[BuilderSlot::Cooler->value] = $cooler;
        }

        $psu = $this->smallestAdequate(BuilderSlot::Psu, $picks, $candidates, $rules);

        if ($psu === null) {
            return null;
        }

        $picks[BuilderSlot::Psu->value] = $psu;

        return $this->check($picks, $rules)->isBlocking() ? null : $picks;
    }

    /**
     * A cooler that is rated for the processor: the strongest one its share of the budget allows, so that a fast
     * processor in a generous build is not left with the smallest cooler that technically passes.
     *
     * @param  array<string, Candidate>  $picks
     */
    private function cooler(array $picks, Candidates $candidates, ActiveRules $rules, int $allowance): ?Candidate
    {
        $smallest = $this->smallestAdequate(BuilderSlot::Cooler, $picks, $candidates, $rules);

        if ($smallest === null) {
            return null;
        }

        $context = $this->context($picks);
        $best = $smallest;

        foreach ($candidates->for(BuilderSlot::Cooler) as $candidate) {
            if ($candidate->priceSen <= $allowance
                && $candidate->number('tdp_rating_watts') > $best->number('tdp_rating_watts')
                && $this->compatibility->checkCandidate($context, $candidate->part, $rules)->status() === CompatibilityReport::COMPATIBLE) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The cheapest part for a slot that raises no warning at all against the parts chosen (a power supply with
     * headroom, a cooler rated for the processor); failing that, the cheapest that at least has no error.
     *
     * @param  array<string, Candidate>  $picks
     */
    private function smallestAdequate(BuilderSlot $slot, array $picks, Candidates $candidates, ActiveRules $rules): ?Candidate
    {
        $context = $this->context($picks);
        $acceptable = null;

        foreach ($candidates->for($slot) as $candidate) { // cheapest first
            $status = $this->compatibility->checkCandidate($context, $candidate->part, $rules)->status();

            if ($status === CompatibilityReport::COMPATIBLE) {
                return $candidate;
            }

            if ($status === CompatibilityReport::WARNING) {
                $acceptable ??= $candidate;
            }
        }

        return $acceptable;
    }

    /**
     * The parts that may go in a slot given what is already chosen.
     *
     * @param  array<string, Candidate>  $picks
     * @return list<Candidate> cheapest first
     */
    private function optionsFor(BuilderSlot $slot, array $picks, Candidates $candidates, ActiveRules $rules, bool $graphicsCard): array
    {
        $context = $this->context($picks);

        return array_values(array_filter($candidates->for($slot), function (Candidate $candidate) use ($slot, $context, $rules, $graphicsCard) {
            // Without a graphics card the picture has to come from the processor.
            if ($slot === BuilderSlot::Cpu && ! $graphicsCard && $candidate->part->text('integrated_graphics') !== 'Yes') {
                return false;
            }

            return ! $this->compatibility->checkCandidate($context, $candidate->part, $rules)->isBlocking();
        }));
    }

    /**
     * @param  list<Candidate>  $options  cheapest first, never empty
     */
    private function choose(BuilderSlot $slot, array $options, int $allowance, BuildBrief $brief): Candidate
    {
        $affordable = fn (array $list) => array_values(array_filter($list, fn (Candidate $c) => $c->priceSen <= $allowance));
        $preferred = $affordable($this->preferred($slot, $options, $brief));

        if ($preferred !== []) {
            // RAM and storage: the cheapest that meets the need (more is bought later, if money is left over).
            // Everything else: the best this share of the budget allows.
            return in_array($slot, [BuilderSlot::Ram, BuilderSlot::Storage], true) ? $preferred[0] : $this->best($slot, $preferred, $brief);
        }

        $any = $affordable($options);

        // Nothing that meets the purpose's needs is affordable: the best affordable part, or simply the cheapest.
        return $any !== [] ? $this->best($slot, $any, $brief) : $options[0];
    }

    /** @param  list<Candidate>  $options  never empty */
    private function best(BuilderSlot $slot, array $options, BuildBrief $brief): Candidate
    {
        usort($options, fn (Candidate $a, Candidate $b) => $this->rank($slot, $b, $brief) <=> $this->rank($slot, $a, $brief));

        return $options[0];
    }

    /**
     * How capable a part is for this purpose, as a list compared item by item (a higher list is a better part).
     *
     * @return list<int|float>
     */
    private function rank(BuilderSlot $slot, Candidate $c, BuildBrief $brief): array
    {
        return match ($slot) {
            // Editing and rendering use every thread; for everything else price is the best guide the catalog offers.
            BuilderSlot::Cpu => $brief->purpose === BuildPurpose::Creation
                ? [$c->number('threads'), $c->number('boost_clock_ghz'), $c->priceSen]
                : [$c->priceSen],
            BuilderSlot::Ram => [$c->number('capacity_gb'), $c->number('speed_mhz'), -$c->number('cas_latency'), -$c->priceSen],
            BuilderSlot::Storage => [$c->number('capacity_gb'), $c->number('read_mbps'), -$c->priceSen],
            default => [$c->priceSen],
        };
    }

    /**
     * The options that suit the purpose, when there are any; otherwise all of them. A preference, never a requirement.
     *
     * @param  list<Candidate>  $options
     * @return list<Candidate>
     */
    private function preferred(BuilderSlot $slot, array $options, BuildBrief $brief): array
    {
        $suits = match ($slot) {
            BuilderSlot::Cpu => match ($brief->purpose) {
                BuildPurpose::Creation => fn (Candidate $c) => $c->number('threads') >= 12,
                default => null,
            },
            BuilderSlot::Gpu => fn (Candidate $c) => $c->number('vram_gb') >= $brief->resolution->preferredVramGb(),
            BuilderSlot::Ram => fn (Candidate $c) => $c->number('capacity_gb') >= $this->ramTargetGb($brief),
            BuilderSlot::Storage => fn (Candidate $c) => $c->part->text('storage_type') === 'NVMe SSD' && $c->number('capacity_gb') >= $this->storageTargetGb($brief),
            default => null,
        };

        if ($suits === null) {
            return $options;
        }

        $suitable = array_values(array_filter($options, $suits));

        return $suitable !== [] ? $suitable : $options;
    }

    private function ramTargetGb(BuildBrief $brief): int
    {
        return match ($brief->purpose) {
            BuildPurpose::Work => 16,
            BuildPurpose::Gaming => $brief->budget->sen >= 500_000 ? 32 : 16,
            BuildPurpose::Creation => 32,
        };
    }

    private function storageTargetGb(BuildBrief $brief): int
    {
        return match ($brief->purpose) {
            BuildPurpose::Work => 500,
            BuildPurpose::Gaming => 1000,
            BuildPurpose::Creation => $brief->budget->sen >= 600_000 ? 2000 : 1000,
        };
    }

    /** @return array<string, int> percent per slot */
    private function shares(BuildBrief $brief, bool $graphicsCard): array
    {
        $shares = self::SHARES[$brief->purpose->value];

        if (! $graphicsCard && $shares['gpu'] > 0) {
            // No card after all: its share goes to the processor, whose built-in graphics now does that job.
            $shares['cpu'] += $shares['gpu'];
            $shares['gpu'] = 0;
        }

        if ($brief->priority === BuildPriority::Performance) {
            $shares['case'] -= 2;
            $shares['storage'] -= 2;
            $shares['motherboard'] -= 1;
            $shares[$graphicsCard && $shares['gpu'] > 0 ? 'gpu' : 'cpu'] += 3;
            $shares['cpu'] += 2;
        }

        return $shares;
    }

    /** @param  array<string, Candidate>  $picks */
    private function total(array $picks): int
    {
        return array_sum(array_map(fn (Candidate $c) => $c->priceSen, $picks));
    }

    /** @param  array<string, Candidate>  $picks */
    private function context(array $picks): BuildContext
    {
        return BuildContext::of(array_map(fn (Candidate $c) => $c->part, array_values($picks)));
    }

    /** @param  array<string, Candidate>  $picks */
    private function check(array $picks, ActiveRules $rules): CompatibilityReport
    {
        return $this->compatibility->check($this->context($picks), $rules);
    }

    private function tooLowMessage(Candidates $candidates, ActiveRules $rules): string
    {
        $cheapest = $this->cheapest($candidates, $rules);

        return $cheapest === null
            ? 'There are not enough parts in stock to put a complete PC together right now.'
            : 'The cheapest complete PC we can build from what is in stock is '.Money::fromSen($this->total($cheapest))->format().'. Try a higher budget.';
    }

    /**
     * @param  array<string, Candidate>  $picks
     * @param  list<string>  $notes
     */
    private function describe(array $picks, BuildBrief $brief, array $notes, ActiveRules $rules): ProposedBuild
    {
        $described = [];

        foreach ($picks as $slotValue => $candidate) {
            $described[$slotValue] = ['product_id' => $candidate->product->id, 'quantity' => 1, 'reason' => $this->reason(BuilderSlot::from($slotValue), $candidate, $brief, $picks, $rules)];
        }

        if (! isset($picks[BuilderSlot::Cooler->value])) {
            $notes[] = 'No separate cooler: this processor comes with one in the box.';
        }

        if (! isset($picks[BuilderSlot::Gpu->value]) && $brief->purpose === BuildPurpose::Work) {
            $notes[] = 'No graphics card: the processor\'s built-in graphics is all that office work and browsing need.';
        }

        $left = $brief->budget->sen - $this->total($picks);

        if ($left >= 5_000) { // RM 50 or more is worth mentioning
            $notes[] = Money::fromSen($left)->format().' of your budget is left over.';
        }

        return new ProposedBuild($described, $notes);
    }

    /** @param  array<string, Candidate>  $picks */
    private function reason(BuilderSlot $slot, Candidate $candidate, BuildBrief $brief, array $picks, ActiveRules $rules): string
    {
        $part = $candidate->part;
        $n = fn (string $key) => rtrim(rtrim(number_format((float) ($part->number($key) ?? 0), 1, '.', ''), '0'), '.');

        return match ($slot) {
            BuilderSlot::Cpu => "{$n('cores')} cores and {$n('threads')} threads, up to {$n('boost_clock_ghz')} GHz. ".match ($brief->purpose) {
                BuildPurpose::Gaming => 'Quick enough not to hold the graphics card back.',
                BuildPurpose::Creation => 'The more threads, the faster a video exports.',
                BuildPurpose::Work => 'Plenty for office work, with graphics built in.',
            },
            BuilderSlot::Motherboard => "{$part->text('socket')} socket to match the processor, {$part->text('ram_type')} memory, {$part->text('form_factor')} size.",
            BuilderSlot::Ram => "{$n('capacity_gb')} GB of {$part->text('ram_type')} at {$n('speed_mhz')} MHz.",
            BuilderSlot::Gpu => "{$part->text('gpu_chipset')} with {$n('vram_gb')} GB of graphics memory, the largest share of the budget because it decides how games run at {$brief->resolution->label()}.",
            BuilderSlot::Storage => $this->size((float) ($part->number('capacity_gb') ?? 0))." {$part->text('storage_type')}: Windows and programs open in seconds.",
            BuilderSlot::Case_ => 'Takes this motherboard'.(isset($picks[BuilderSlot::Gpu->value]) ? ' and has room for the graphics card.' : '.'),
            BuilderSlot::Cooler => "Rated for {$n('tdp_rating_watts')} W. This processor does not come with a cooler.",
            BuilderSlot::Psu => $this->psuReason($candidate, $picks, $rules),
        };
    }

    /** 500 -> "500 GB", 2000 -> "2 TB". */
    private function size(float $gigabytes): string
    {
        $trim = fn (float $value) => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $gigabytes >= 1000 ? $trim($gigabytes / 1000).' TB' : $trim($gigabytes).' GB';
    }

    /** @param  array<string, Candidate>  $picks */
    private function psuReason(Candidate $psu, array $picks, ActiveRules $rules): string
    {
        $needs = $this->check($picks, $rules)->metrics['estimated_power_watts'] ?? null;
        $watts = (int) $psu->number('wattage');

        return "{$watts} W, {$psu->part->text('efficiency')}.".($needs ? " The parts draw about {$needs} W, so there is room to spare." : '');
    }
}
