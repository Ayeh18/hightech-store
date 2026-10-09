<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ShipOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'carrier' => ['required', 'string', 'max:60'],
            'tracking_number' => ['required', 'string', 'max:100'],
        ];
    }
}
