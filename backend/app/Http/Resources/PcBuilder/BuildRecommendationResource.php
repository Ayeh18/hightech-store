<?php

namespace App\Http\Resources\PcBuilder;

use App\Enums\BuilderSlot;
use App\Services\Recommendations\Recommendation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A suggested PC. `build` is the same shape as the builder's own validation answer: the parts as the shop prices and
 * judges them right now, whoever suggested them.
 *
 * @mixin Recommendation
 */
class BuildRecommendationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $brief = $this->brief;

        return [
            'id' => $this->record->id,
            /**
             * Who chose the parts: the shop's fixed rules, or an AI service whose choice then passed the shop's checks.
             *
             * @var 'rules'|'ai'
             */
            'source' => $this->record->source,
            'brief' => [
                'budget' => $brief->budget,
                'purpose' => ['value' => $brief->purpose->value, 'label' => $brief->purpose->label()],
                'priority' => ['value' => $brief->priority->value, 'label' => $brief->priority->label()],
                'resolution' => ['value' => $brief->resolution->value, 'label' => $brief->resolution->label()],
                'games' => $brief->games,
            ],
            'build' => new EvaluatedBuildResource($this->build),
            // Why each part, in builder order.
            'reasons' => array_values(array_filter(array_map(
                fn (BuilderSlot $slot) => isset($this->reasons[$slot->value]) ? ['slot' => $slot->value, 'slot_label' => $slot->label(), 'reason' => $this->reasons[$slot->value]] : null,
                BuilderSlot::cases(),
            ))),
            'notes' => $this->notes,
            'remaining' => Money::fromSen($brief->budget->sen - $this->build->priced->subtotal->sen),
        ];
    }
}
