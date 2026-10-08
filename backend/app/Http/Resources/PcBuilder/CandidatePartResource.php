<?php

namespace App\Http\Resources\PcBuilder;

use App\Enums\BuilderSlot;
use App\Http\Resources\Catalog\ProductListResource;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\PcBuilder\CandidatePart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A product offered for a slot, with how well it fits the rest of the build. @mixin CandidatePart */
class CandidatePartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...(new ProductListResource($this->product))->toArray($request),
            'compatibility' => [
                'status' => $this->report->status(),
                'issues' => array_map(fn (CompatibilityIssue $i) => [
                    'rule' => $i->rule,
                    'severity' => $i->severity,
                    'message' => $i->message,
                    'slots' => array_map(fn (BuilderSlot $s) => $s->value, $i->slots),
                ], $this->report->issues),
            ],
        ];
    }
}
