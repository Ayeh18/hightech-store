<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // "image" checks the real file content, not just the extension the client claims.
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072', 'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000'],
            'alt' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
