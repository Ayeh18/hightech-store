<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks that this is the customer's own order
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'min:3', 'max:500']]; // why they want it back
    }
}
