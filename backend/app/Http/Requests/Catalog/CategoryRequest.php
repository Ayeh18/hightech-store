<?php

namespace App\Http\Requests\Catalog;

use App\Enums\BuilderSlot;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Category|null $category */
        $category = $this->route('category');
        $required = $category === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'builder_slot' => ['nullable', Rule::enum(BuilderSlot::class), Rule::unique('categories', 'builder_slot')->ignore($category?->id)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        // On create the slug is derived from the name, so check that it is free as well.
        return [function ($validator) {
            if ($this->route('category') === null && ! $this->has('slug') && $this->filled('name')
                && Category::where('slug', Str::slug($this->input('name')))->exists()) {
                $validator->errors()->add('name', 'A category with this name already exists.');
            }
        }];
    }
}
