<?php

namespace App\Enums;

/** What matters most to the customer when two builds both fit the budget. */
enum BuildPriority: string
{
    case Performance = 'performance'; // put as much as possible into the processor and graphics card
    case Balanced = 'balanced';
    case Value = 'value';             // leave some of the budget unspent

    public function label(): string
    {
        return match ($this) {
            self::Performance => 'Best performance',
            self::Balanced => 'Balanced',
            self::Value => 'Save money',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $priority) => $priority->value, self::cases());
    }
}
