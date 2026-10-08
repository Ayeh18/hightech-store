<?php

namespace App\Http\Requests\Catalog;

use App\Services\Catalog\ProductFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates the query string of GET /api/products (public shop and admin list). */
class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'brands' => ['nullable', 'array', 'max:20'],
            'brands.*' => ['string', 'max:100'],
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => array_filter(['nullable', 'integer', 'min:0', $this->has('price_min') ? 'gte:price_min' : null]),
            'in_stock' => ['nullable', 'boolean'],
            'specs' => ['nullable', 'array', 'max:20'],
            'sort' => ['nullable', Rule::in(ProductFilters::SORTS)],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): ProductFilters
    {
        return ProductFilters::fromArray($this->validated());
    }
}
