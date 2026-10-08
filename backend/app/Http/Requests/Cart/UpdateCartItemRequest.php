<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is checked by CartItemPolicy
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['quantity' => ['required', 'integer', 'min:1', 'max:99']];
    }
}
