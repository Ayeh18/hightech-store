<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only choices are accepted here: which address, which coupon code, an optional note. There is deliberately no
 * field for a price or a total. They are calculated by the server and anything extra the browser sends is ignored.
 */
class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The idempotency key travels as a header, the way payment APIs usually do it.
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'address_id' => ['required', 'integer', 'min:1'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            /** @ignoreParam It arrives as the `Idempotency-Key` header (see prepareForValidation), so it is not a body field. */
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ];
    }

    public function messages(): array
    {
        return ['idempotency_key.regex' => 'The Idempotency-Key header must be 8 to 64 letters, numbers, dashes or underscores.'];
    }
}
