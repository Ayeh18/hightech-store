<?php

namespace App\Enums;

/**
 * The PC-builder slots, in the order they appear on screen. A category joins the builder through
 * categories.builder_slot (a database value), so which products fill a slot is data, not code.
 */
enum BuilderSlot: string
{
    case Cpu = 'cpu';
    case Motherboard = 'motherboard';
    case Ram = 'ram';
    case Gpu = 'gpu';
    case Storage = 'storage';
    case Psu = 'psu';
    case Case_ = 'case';
    case Cooler = 'cooler';

    public function label(): string
    {
        return match ($this) {
            self::Cpu => 'CPU',
            self::Motherboard => 'Motherboard',
            self::Ram => 'RAM',
            self::Gpu => 'GPU',
            self::Storage => 'Storage',
            self::Psu => 'Power supply',
            self::Case_ => 'Case',
            self::Cooler => 'CPU cooler',
        };
    }

    /** A complete build needs these. GPU is optional (integrated graphics) and so is a separate cooler. */
    public function isRequired(): bool
    {
        return ! in_array($this, [self::Gpu, self::Cooler], true);
    }

    /** RAM kits and drives can be added more than once; everything else is a single part. */
    public function maxQuantity(): int
    {
        return match ($this) {
            self::Ram, self::Storage => 4,
            default => 1,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $slot) => $slot->value, self::cases());
    }
}
