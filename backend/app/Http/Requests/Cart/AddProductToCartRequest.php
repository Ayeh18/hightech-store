<?php

namespace App\Http\Requests\Cart;

use App\Services\Cart\CartService;
use Illuminate\Foundation\Http\FormRequest;

class AddProductToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            // The exact limits (and the stock check) are enforced by CartService; this only checks the shape.
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.CartService::MAX_PRODUCT_QUANTITY],
        ];
    }

    public function quantity(): int
    {
        return (int) ($this->validated()['quantity'] ?? 1);
    }
}
