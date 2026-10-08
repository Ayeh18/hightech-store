<?php

namespace App\Http\Requests\PcBuilder;

/** Creating a build needs a name and parts; updating may change either one. */
class SaveBuildRequest extends BuildSelectionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->route('build') === null;
        $rules = parent::rules();

        if (! $creating) {
            $rules['items'] = ['sometimes', ...array_slice($rules['items'], 1)];
        }

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            ...$rules,
        ];
    }

    /** @return array<int, array{slot: string, product_id: int, quantity: int}>|null */
    public function selectionOrNull(): ?array
    {
        return $this->has('items') ? $this->selection() : null;
    }
}
