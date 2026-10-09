<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'address_id' => ['nullable', 'integer', 'min:1'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
