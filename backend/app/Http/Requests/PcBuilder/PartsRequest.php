<?php

namespace App\Http\Requests\PcBuilder;

use App\Enums\BuilderSlot;
use App\Http\Requests\Catalog\ProductIndexRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** GET /api/pc-builder/parts: the normal product filters plus the slot and the parts chosen so far. */
class PartsRequest extends ProductIndexRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'slot' => ['required', Rule::enum(BuilderSlot::class)],
            // parts[cpu][id]=5&parts[ram][id]=12&parts[ram][qty]=2
            'parts' => ['nullable', 'array', 'max:'.count(BuilderSlot::cases())],
            'parts.*.id' => ['required', 'integer', 'min:1'],
            'parts.*.qty' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'include_incompatible' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_keys((array) $this->input('parts', [])) as $key) {
                if (BuilderSlot::tryFrom((string) $key) === null) {
                    $validator->errors()->add("parts.{$key}", "\"{$key}\" is not a builder slot.");
                }
            }
        }];
    }

    public function slot(): BuilderSlot
    {
        return BuilderSlot::from($this->validated('slot'));
    }

    /** @return array<string, array{id: int, qty?: int}> */
    public function selectedParts(): array
    {
        return (array) ($this->validated()['parts'] ?? []);
    }
}
