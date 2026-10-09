<?php

namespace App\Enums;

/** States and federal territories, used for delivery addresses and shipping zones. */
enum MalaysianState: string
{
    case Johor = 'Johor';
    case Kedah = 'Kedah';
    case Kelantan = 'Kelantan';
    case Melaka = 'Melaka';
    case NegeriSembilan = 'Negeri Sembilan';
    case Pahang = 'Pahang';
    case Perak = 'Perak';
    case Perlis = 'Perlis';
    case PulauPinang = 'Pulau Pinang';
    case Sabah = 'Sabah';
    case Sarawak = 'Sarawak';
    case Selangor = 'Selangor';
    case Terengganu = 'Terengganu';
    case KualaLumpur = 'Kuala Lumpur';
    case Labuan = 'Labuan';
    case Putrajaya = 'Putrajaya';

    public function isEastMalaysia(): bool
    {
        return in_array($this, [self::Sabah, self::Sarawak, self::Labuan], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $state) => $state->value, self::cases());
    }
}
