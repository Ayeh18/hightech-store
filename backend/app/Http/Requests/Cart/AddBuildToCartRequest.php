<?php

namespace App\Http\Requests\Cart;

use App\Http\Requests\PcBuilder\BuildSelectionRequest;
use App\Services\Cart\CartService;

/**
 * Adds a PC to the cart from EITHER a saved build (`build_id`) OR the parts currently in the builder (`items`).
 * Either way the server re-validates the build; nothing about it is taken on trust.
 */
class AddBuildToCartRequest extends BuildSelectionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'build_id' => ['required_without:items', 'prohibits:items', 'string', 'size:26'],
            'items' => ['required_without:build_id', 'array', 'max:8'],
            'name' => ['nullable', 'string', 'max:100'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.CartService::MAX_BUILD_QUANTITY],
        ];
    }

    public function quantity(): int
    {
        return (int) ($this->validated()['quantity'] ?? 1);
    }

    public function buildName(): ?string
    {
        $name = trim((string) ($this->validated()['name'] ?? ''));

        return $name === '' ? null : $name;
    }
}
