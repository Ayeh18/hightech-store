<?php

namespace App\Enums;

enum CartItemKind: string
{
    case Product = 'product';
    case Build = 'build';
}
