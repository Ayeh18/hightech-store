<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'key', 'value_string', 'value_number', 'value_json'])]
class ProductSpecification extends Model
{
    protected function casts(): array
    {
        return ['value_json' => 'array'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The stored value as a plain PHP value: string, int|float, or array. */
    public function getValueAttribute(): string|int|float|array|null
    {
        if ($this->value_json !== null) {
            return $this->value_json;
        }

        if ($this->value_number !== null) {
            $number = (float) $this->value_number;

            return floor($number) === $number ? (int) $number : $number;
        }

        return $this->value_string;
    }
}
