<?php

// brand, name, price RM, discount RM, stock, type
$r = fn ($brand, $name, $price, $disc, $stock, $type) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => null, 'weight' => 50,
    'specs' => ['accessory_type' => $type],
];

return [
    $r('Arctic', 'MX-4 Thermal Paste 4g', 29, null, 90, 'Thermal Paste'),
    $r('Noctua', 'NT-H2 Thermal Paste 3.5g', 49, null, 40, 'Thermal Paste'),
    $r('Thermal Grizzly', 'Kryonaut Thermal Paste 1g', 49, null, 35, 'Thermal Paste'),
    $r('Corsair', 'Premium Individually Sleeved PSU Cables Starter Kit', 249, null, 12, 'Cable Kit'),
    $r('Cooler Master', 'Universal GPU Support Bracket', 59, null, 27, 'GPU Support'),
    $r('Lian Li', 'Velcro Cable Management Strap Set (10-pack)', 25, null, 60, 'Cable Management'),
    $r('iFixit', 'Mako Driver Kit 64-bit Precision Set', 149, null, 14, 'Tools'),
];
