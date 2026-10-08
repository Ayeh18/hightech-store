<?php

namespace App\Http\Requests\Catalog;

use App\Enums\SpecType;
use App\Models\Category;
use App\Models\SpecDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpecDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var SpecDefinition|null $definition */
        $definition = $this->route('specDefinition');
        /** @var Category|null $category */
        $category = $this->route('category');

        $shared = [
            'label' => [$definition ? 'sometimes' : 'required', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:16'],
            'options' => ['nullable', 'array', 'max:100'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'is_required' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
            'show_on_card' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ];

        if ($definition) {
            // key and type cannot change: products already store values under them.
            return $shared + ['key' => ['prohibited'], 'type' => ['prohibited']];
        }

        return $shared + [
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('spec_definitions', 'key')->where('category_id', $category->id)],
            'type' => ['required', Rule::enum(SpecType::class)],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function ($validator) {
            $type = $this->route('specDefinition')?->type->value ?? $this->input('type');

            if (in_array($type, ['enum', 'list'], true) && ! $this->filled('options') && ! $this->route('specDefinition')) {
                $validator->errors()->add('options', 'Enum and list specifications need a list of allowed options.');
            }
        }];
    }
}
