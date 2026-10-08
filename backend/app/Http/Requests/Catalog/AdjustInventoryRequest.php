<?php

namespace App\Http\Requests\Catalog;

use App\Services\Catalog\InventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'change' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'reason' => ['required', Rule::in(InventoryService::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
