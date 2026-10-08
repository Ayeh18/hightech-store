<?php

// brand, name, price RM, discount RM, stock, socket, cores, threads, base GHz, boost GHz, TDP W, iGPU, memory, cooler included
$r = fn ($brand, $name, $price, $disc, $stock, $socket, $cores, $threads, $base, $boost, $tdp, $igpu, $mem, $cooler) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 36, 'weight' => 120,
    'specs' => [
        'socket' => $socket, 'cores' => $cores, 'threads' => $threads, 'base_clock_ghz' => $base, 'boost_clock_ghz' => $boost,
        'tdp_watts' => $tdp, 'integrated_graphics' => $igpu, 'memory_types' => $mem, 'cooler_included' => $cooler,
    ],
];

return [
    $r('AMD', 'Ryzen 5 5600 Desktop Processor', 459, null, 60, 'AM4', 6, 12, 3.5, 4.4, 65, 'No', ['DDR4'], 'Yes'),
    $r('AMD', 'Ryzen 7 5700X Desktop Processor', 649, 599, 35, 'AM4', 8, 16, 3.4, 4.6, 65, 'No', ['DDR4'], 'No'),
    $r('AMD', 'Ryzen 5 7600 Desktop Processor', 699, 649, 40, 'AM5', 6, 12, 3.8, 5.1, 65, 'Yes', ['DDR5'], 'Yes'),
    $r('AMD', 'Ryzen 5 7600X Desktop Processor', 799, null, 25, 'AM5', 6, 12, 4.7, 5.3, 105, 'Yes', ['DDR5'], 'No'),
    $r('AMD', 'Ryzen 7 7700 Desktop Processor', 1099, null, 30, 'AM5', 8, 16, 3.8, 5.3, 65, 'Yes', ['DDR5'], 'Yes'),
    $r('AMD', 'Ryzen 7 7800X3D Desktop Processor', 1599, null, 18, 'AM5', 8, 16, 4.2, 5.0, 120, 'Yes', ['DDR5'], 'No'),
    $r('AMD', 'Ryzen 9 7900X Desktop Processor', 1499, 1399, 12, 'AM5', 12, 24, 4.7, 5.6, 170, 'Yes', ['DDR5'], 'No'),
    $r('AMD', 'Ryzen 5 9600X Desktop Processor', 999, null, 22, 'AM5', 6, 12, 3.9, 5.4, 65, 'Yes', ['DDR5'], 'No'),
    $r('AMD', 'Ryzen 7 9700X Desktop Processor', 1299, null, 20, 'AM5', 8, 16, 3.8, 5.5, 65, 'Yes', ['DDR5'], 'No'),
    $r('AMD', 'Ryzen 7 9800X3D Desktop Processor', 2099, null, 8, 'AM5', 8, 16, 4.7, 5.2, 120, 'Yes', ['DDR5'], 'No'),
    $r('AMD', 'Ryzen 9 9950X Desktop Processor', 2499, null, 6, 'AM5', 16, 32, 4.3, 5.7, 170, 'Yes', ['DDR5'], 'No'),
    $r('Intel', 'Core i3-12100F Desktop Processor', 329, null, 50, 'LGA1700', 4, 8, 3.3, 4.3, 58, 'No', ['DDR4', 'DDR5'], 'Yes'),
    $r('Intel', 'Core i5-12400F Desktop Processor', 549, 499, 45, 'LGA1700', 6, 12, 2.5, 4.4, 65, 'No', ['DDR4', 'DDR5'], 'Yes'),
    $r('Intel', 'Core i5-13400F Desktop Processor', 729, null, 28, 'LGA1700', 10, 16, 2.5, 4.6, 65, 'No', ['DDR4', 'DDR5'], 'Yes'),
    $r('Intel', 'Core i5-14600K Desktop Processor', 1199, null, 15, 'LGA1700', 14, 20, 3.5, 5.3, 125, 'Yes', ['DDR4', 'DDR5'], 'No'),
    $r('Intel', 'Core i7-14700K Desktop Processor', 1699, 1599, 10, 'LGA1700', 20, 28, 3.4, 5.6, 125, 'Yes', ['DDR4', 'DDR5'], 'No'),
    $r('Intel', 'Core i9-14900K Desktop Processor', 2299, null, 7, 'LGA1700', 24, 32, 3.2, 6.0, 125, 'Yes', ['DDR4', 'DDR5'], 'No'),
    $r('Intel', 'Core Ultra 5 245K Desktop Processor', 1199, null, 14, 'LGA1851', 14, 14, 4.2, 5.2, 125, 'Yes', ['DDR5'], 'No'),
    $r('Intel', 'Core Ultra 7 265K Desktop Processor', 1599, null, 9, 'LGA1851', 20, 20, 3.9, 5.5, 125, 'Yes', ['DDR5'], 'No'),
];
