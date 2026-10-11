<?php

namespace App\Enums;

/** What the PC is for. It decides where the money goes: a gaming PC spends on graphics, an office PC needs none. */
enum BuildPurpose: string
{
    case Gaming = 'gaming';
    case Creation = 'creation';
    case Work = 'work';

    public function label(): string
    {
        return match ($this) {
            self::Gaming => 'Gaming',
            self::Creation => 'Video editing and 3D',
            self::Work => 'Office, study and browsing',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $purpose) => $purpose->value, self::cases());
    }
}
