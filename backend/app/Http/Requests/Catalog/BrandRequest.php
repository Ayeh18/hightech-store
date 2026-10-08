<?php

namespace App\Http\Requests\Catalog;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Brand|null $brand */
        $brand = $this->route('brand');

        return [
            'name' => [$brand === null ? 'required' : 'sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('brands', 'slug')->ignore($brand?->id)],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function ($validator) {
            if ($this->route('brand') === null && ! $this->has('slug') && $this->filled('name')
                && Brand::where('slug', Str::slug($this->input('name')))->exists()) {
                $validator->errors()->add('name', 'A brand with this name already exists.');
            }
        }];
    }
}
