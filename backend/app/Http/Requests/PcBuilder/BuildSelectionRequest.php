<?php

namespace App\Http\Requests\PcBuilder;

use App\Enums\BuilderSlot;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared rules for a list of selected parts: `items: [{slot, product_id, quantity}]`. */
class BuildSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:'.count(BuilderSlot::cases())],
            'items.*.slot' => ['required', 'distinct', Rule::enum(BuilderSlot::class)],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:8'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            // Each slot has its own quantity limit (e.g. one CPU, up to four RAM kits).
            foreach ((array) $this->input('items', []) as $index => $item) {
                $slot = BuilderSlot::tryFrom((string) ($item['slot'] ?? ''));

                if ($slot !== null && (int) ($item['quantity'] ?? 1) > $slot->maxQuantity()) {
                    $validator->errors()->add("items.{$index}.quantity", "You can select at most {$slot->maxQuantity()} for {$slot->label()}.");
                }
            }
        }];
    }

    /** @return array<int, array{slot: string, product_id: int, quantity: int}> */
    public function selection(): array
    {
        return array_map(fn (array $item) => [
            'slot' => $item['slot'],
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) ($item['quantity'] ?? 1),
        ], $this->validated()['items']);
    }
}
