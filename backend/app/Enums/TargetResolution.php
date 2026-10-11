<?php

namespace App\Enums;

/** The screen the PC will drive. A sharper screen needs a graphics card with more memory. */
enum TargetResolution: string
{
    case FullHd = '1080p';
    case QuadHd = '1440p';
    case UltraHd = '4k';

    public function label(): string
    {
        return match ($this) {
            self::FullHd => '1080p (Full HD)',
            self::QuadHd => '1440p (QHD)',
            self::UltraHd => '4K',
        };
    }

    /** The graphics memory worth having at this resolution, in GB. A preference, never a hard rule. */
    public function preferredVramGb(): int
    {
        return match ($this) {
            self::FullHd => 8,
            self::QuadHd => 12,
            self::UltraHd => 16,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $resolution) => $resolution->value, self::cases());
    }
}
