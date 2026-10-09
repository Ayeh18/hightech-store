<?php

namespace App\Http\Requests\Account;

use App\Enums\MalaysianState;
use App\Models\Address;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is checked by AddressPolicy
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->route('address') instanceof Address ? 'sometimes' : 'required';

        return [
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => [$required, 'string', 'max:100'],
            'phone' => [$required, 'string', 'regex:/^[0-9+\-\s()]{7,32}$/'],
            'line1' => [$required, 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => [$required, 'string', 'max:100'],
            'state' => [$required, Rule::in(MalaysianState::values())],
            'postcode' => [$required, 'regex:/^\d{5}$/'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'postcode.regex' => 'The postcode must be 5 digits.',
            'phone.regex' => 'Enter a valid phone number.',
        ];
    }
}
