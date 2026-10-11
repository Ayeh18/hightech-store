<?php

namespace App\Services\Recommendations;

use App\Enums\BuilderSlot;
use App\Enums\RecommenderFailure;
use App\Models\BuildRecommendation;
use App\Models\User;
use App\Services\PcBuilder\BuildEvaluator;
use App\Services\PcBuilder\EvaluatedBuild;
use App\Services\Recommendations\Contracts\BuildRecommender;
use App\Services\Recommendations\Exceptions\CannotRecommend;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Suggests a PC for a brief. The suggesting itself is done by a recommender (the shop's rules, or an AI service asked
 * first); this class is the part that does not trust it.
 *
 *   products in the database -> a recommender's proposal -> real products? -> in stock, compatible? -> within budget?
 *
 * Only a proposal that passes every step reaches a customer, and what they see is the build as the shop itself prices
 * and judges it (BuildEvaluator, the same code the builder, the cart and checkout use), never the recommender's own
 * claims. An AI that invents a product, quotes a wrong price or ignores a socket simply fails a step, and the rules
 * take over.
 */
class RecommendationService
{
    /** A warning from one of these means the PC is missing something it cannot run without. */
    private const ESSENTIAL = ['cpu_needs_graphics_card', 'cpu_cooler_required'];

    public function __construct(
        private readonly RuleBasedRecommender $rules,
        private readonly BuildEvaluator $evaluator,
        private readonly ?BuildRecommender $assistant = null, // asked first when one is configured (the AI)
    ) {}

    /** @throws CannotRecommend when no build can be made for this brief (the budget is below the cheapest PC) */
    public function recommend(BuildBrief $brief, ?User $user = null): Recommendation
    {
        $candidates = Candidates::load();
        $fallbackReason = null;
        $fallbackKind = null;
        $accepted = null;

        if ($this->assistant !== null) {
            try {
                $proposal = $this->assistant->propose($brief, $candidates);
                $accepted = [$this->assistant->name(), $proposal, $this->validate($proposal, $brief, $candidates)];
            } catch (CannotRecommend $e) {
                // Not an error for the customer: the rules below answer instead. Kept for the shop's own records.
                $fallbackReason = $e->getMessage();
                $fallbackKind = $e->failure;
                Log::info('The AI recommendation was not used.', ['reason' => $fallbackReason]);
            }
        }

        if ($accepted === null) {
            $proposal = $this->rules->propose($brief, $candidates);
            $accepted = [$this->rules->name(), $proposal, $this->validate($proposal, $brief, $candidates)];
        }

        [$source, $proposal, $build] = $accepted;
        $notes = $proposal->notes;

        if ($fallbackReason !== null) {
            $notes[] = 'The AI suggestion could not be used this time, so this build was chosen by the store\'s own rules.';
        }

        $record = BuildRecommendation::create([
            'user_id' => $user?->id,
            'budget' => $brief->budget,
            'purpose' => $brief->purpose,
            'priority' => $brief->priority,
            'resolution' => $brief->resolution,
            'games' => $brief->games ?: null,
            'source' => $source,
            'fallback_reason' => $fallbackReason === null ? null : mb_substr($fallbackReason, 0, 255),
            'fallback_kind' => $fallbackKind,
            'total' => $build->priced->subtotal,
            'selection' => array_map(fn (array $pick) => $pick['product_id'], $proposal->picks),
        ]);

        return new Recommendation($record, $brief, $build, array_map(fn (array $pick) => $pick['reason'], $proposal->picks), $notes);
    }

    /**
     * Every check a proposal must pass, whoever made it.
     *
     * @throws CannotRecommend naming the first check it failed
     */
    private function validate(ProposedBuild $proposal, BuildBrief $brief, Candidates $candidates): EvaluatedBuild
    {
        // 1. Real products, in the right slots, on sale and in stock: only what was offered may be chosen.
        foreach ($proposal->picks as $slotValue => $pick) {
            $slot = BuilderSlot::tryFrom((string) $slotValue);

            if ($slot === null || $candidates->find($slot, $pick['product_id']) === null) {
                throw new CannotRecommend("It chose product #{$pick['product_id']} for \"{$slotValue}\", which is not one of the products it was offered for that slot.", RecommenderFailure::NotOffered);
            }

            if ($pick['quantity'] < 1 || $pick['quantity'] > $slot->maxQuantity()) {
                throw new CannotRecommend("It chose {$pick['quantity']} of a part for \"{$slotValue}\".");
            }
        }

        // 2. Priced and judged by the shop: database prices, stock and the compatibility rules.
        try {
            $build = $this->evaluator->evaluateSelection($proposal->selection());
        } catch (ValidationException $e) {
            throw new CannotRecommend('The shop could not price it: '.collect($e->errors())->flatten()->first());
        }

        if (! $build->priced->isComplete()) {
            throw new CannotRecommend('It left out a part every PC needs: '.implode(', ', array_map(fn (BuilderSlot $s) => $s->label(), $build->priced->missingSlots)).'.', RecommenderFailure::Incomplete);
        }

        if (! $build->isPurchasable()) {
            $problem = $build->compatibility->isBlocking()
                ? collect($build->compatibility->issues)->firstWhere('severity', 'error')->message
                : ($build->priced->issues[0]->message ?? 'a part cannot be bought');

            throw new CannotRecommend("Its parts do not work together: {$problem}", RecommenderFailure::Incompatible);
        }

        foreach ($build->compatibility->issues as $issue) {
            if (in_array($issue->rule, self::ESSENTIAL, true)) {
                throw new CannotRecommend("The PC would not be usable: {$issue->message}", RecommenderFailure::Unusable);
            }
        }

        // 3. Within budget, at the shop's prices.
        if ($build->priced->subtotal->sen > $brief->budget->sen) {
            throw new CannotRecommend("It costs {$build->priced->subtotal->format()}, which is over the budget of {$brief->budget->format()}.", RecommenderFailure::OverBudget);
        }

        return $build;
    }
}
