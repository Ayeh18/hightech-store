<?php

namespace App\Enums;

/** How a specification value is stored and validated. */
enum SpecType: string
{
    case String = 'string';  // free text, e.g. chipset name
    case Enum = 'enum';      // one of `options`, e.g. socket AM5
    case Number = 'number';  // numeric, optional unit, e.g. 6 cores, 227 mm
    case List = 'list';      // several of `options`, e.g. supported form factors

    /** The product_specifications column that holds values of this type. */
    public function column(): string
    {
        return match ($this) {
            self::String, self::Enum => 'value_string',
            self::Number => 'value_number',
            self::List => 'value_json',
        };
    }
}
