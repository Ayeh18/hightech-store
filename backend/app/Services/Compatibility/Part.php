<?php

namespace App\Services\Compatibility;

use App\Enums\BuilderSlot;
use App\Models\Product;

/**
 * One selected part, reduced to what the rules need: its slot, name, quantity and specs.
 * Rules never touch Eloquent, so they are fast and easy to test.
 */
final readonly class Part
{
    /** @param  array<string, string|int|float|array<int, string>|null>  $specs  e.g. ['socket' => 'AM5', 'cores' => 6] */
    public function __construct(
        public BuilderSlot $slot,
        public string $name,
        public array $specs,
        public int $quantity = 1,
    ) {}

    /** Needs the product's `specifications` relation to be loaded. */
    public static function fromProduct(BuilderSlot $slot, Product $product, int $quantity = 1): self
    {
        return new self($slot, $product->name, $product->specValues(), $quantity);
    }

    public function spec(string $key): string|int|float|array|null
    {
        return $this->specs[$key] ?? null;
    }

    /** A numeric spec, or null when it is missing (rules skip a check when data is missing). */
    public function number(string $key): int|float|null
    {
        $value = $this->spec($key);

        return is_int($value) || is_float($value) ? $value : null;
    }

    public function text(string $key): ?string
    {
        $value = $this->spec($key);

        return is_string($value) ? $value : null;
    }

    /** @return array<int, string>|null */
    public function list(string $key): ?array
    {
        $value = $this->spec($key);

        return is_array($value) ? $value : null;
    }
}
