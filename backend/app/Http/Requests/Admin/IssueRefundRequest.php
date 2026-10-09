<?php

namespace App\Http\Requests\Admin;

use App\Enums\RefundReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'], // in sen, like every amount in this API
            'reason' => ['required', Rule::in([RefundReason::Goodwill->value, RefundReason::LatePayment->value])],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
