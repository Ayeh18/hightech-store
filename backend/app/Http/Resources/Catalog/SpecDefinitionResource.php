<?php

namespace App\Http\Resources\Catalog;

use App\Models\SpecDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SpecDefinition */
class SpecDefinitionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'unit' => $this->unit,
            'options' => $this->options,
            'is_required' => $this->is_required,
            'is_filterable' => $this->is_filterable,
            'show_on_card' => $this->show_on_card,
            'sort_order' => $this->sort_order,
        ];
    }
}
