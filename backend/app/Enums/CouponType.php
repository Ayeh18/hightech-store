<?php

namespace App\Enums;

enum CouponType: string
{
    case Percent = 'percent'; // value is in basis points: 1000 = 10%
    case Fixed = 'fixed';     // value is in sen: 5000 = RM 50.00
}
