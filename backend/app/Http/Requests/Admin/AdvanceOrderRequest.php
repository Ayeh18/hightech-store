<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires the orders.manage permission
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::in([OrderStatus::Processing->value, OrderStatus::Packed->value])]];
    }
}
