<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rules shared by creating and updating a product. Amounts are integer sen (RM 1.00 = 100). */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Access is enforced by the route's can:products.manage middleware.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');
        $creating = $product === null;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'category_id' => [$required, 'integer', Rule::exists('categories', 'id')],
            'brand_id' => [$required, 'integer', Rule::exists('brands', 'id')],
            'sku' => [$required, 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products', 'sku')->ignore($product?->id)],
            'name' => [$required, 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => [$required, 'integer', 'min:1', 'max:100000000'],
            'discount_price' => array_filter(['nullable', 'integer', 'min:1', $this->has('price') ? 'lt:price' : null]),
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:240'],
            'warranty_info' => ['nullable', 'string', 'max:255'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            // Per-category validation of the specs happens in SpecificationValidator.
            'specs' => [$creating || $this->categoryChanged($product) ? 'required' : 'sometimes', 'array'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function ($validator) {
            /** @var Product|null $product */
            $product = $this->route('product');

            // On update, a discount must also stay below the existing price when only the discount is sent.
            if ($product && $this->has('discount_price') && ! $this->has('price') && $this->integer('discount_price') >= $product->price->sen) {
                $validator->errors()->add('discount_price', 'The discount price must be lower than the regular price.');
            }

            // Same idea in reverse: raising nothing but price cannot be below the stored discount.
            if ($product && $this->has('price') && ! $this->has('discount_price') && $product->discount_price && $this->integer('price') <= $product->discount_price->sen) {
                $validator->errors()->add('price', 'The price must stay above the current discount price.');
            }
        }];
    }

    private function categoryChanged(?Product $product): bool
    {
        return $product !== null && $this->has('category_id') && (int) $this->input('category_id') !== $product->category_id;
    }
}
