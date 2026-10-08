<?php

namespace App\Enums;

/** The PC-builder slots. A category opts in through categories.builder_slot. */
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
}
