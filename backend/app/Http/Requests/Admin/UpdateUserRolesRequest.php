<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires the users.manage permission
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:5'],
            'roles.*' => ['string', 'distinct', Rule::in(Role::pluck('slug')->all())],
        ];
    }
}
