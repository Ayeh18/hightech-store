<?php

// brand, name, price RM, discount RM, stock, watts, efficiency, modularity, form factor, 12V-2x6 connector
$r = fn ($brand, $name, $price, $disc, $stock, $watts, $eff, $mod, $ff, $pcie5) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 60, 'weight' => 1800,
    'specs' => [
        'wattage' => $watts, 'efficiency' => $eff, 'modularity' => $mod, 'form_factor' => $ff, 'pcie5_connector' => $pcie5,
    ],
];

return [
    $r('Gigabyte', 'P450B 450W Power Supply', 179, null, 25, 450, '80+ Bronze', 'Non-modular', 'ATX', 'No'),
    $r('Seasonic', 'S12III 500W Power Supply', 219, null, 20, 500, '80+ Bronze', 'Non-modular', 'ATX', 'No'),
    $r('Corsair', 'CV550 550W Power Supply', 229, null, 34, 550, '80+ Bronze', 'Non-modular', 'ATX', 'No'),
    $r('MSI', 'MAG A650BN 650W Power Supply', 259, null, 28, 650, '80+ Bronze', 'Non-modular', 'ATX', 'No'),
    $r('Corsair', 'CX650 650W Power Supply', 279, 259, 30, 650, '80+ Bronze', 'Non-modular', 'ATX', 'No'),
    $r('Cooler Master', 'MWE Bronze 750 V2 Power Supply', 329, null, 22, 750, '80+ Bronze', 'Non-modular', 'ATX', 'No'),
    $r('be quiet!', 'Pure Power 12 M 750W Power Supply', 499, null, 14, 750, '80+ Gold', 'Fully modular', 'ATX', 'Yes'),
    $r('Corsair', 'RM750e 750W Power Supply', 539, null, 19, 750, '80+ Gold', 'Fully modular', 'ATX', 'Yes'),
    $r('Seasonic', 'Focus GX-850 850W Power Supply', 689, null, 12, 850, '80+ Gold', 'Fully modular', 'ATX', 'Yes'),
    $r('Corsair', 'RM1000e 1000W Power Supply', 749, 699, 11, 1000, '80+ Gold', 'Fully modular', 'ATX', 'Yes'),
    $r('Cooler Master', 'V850 SFX Gold 850W Power Supply', 749, null, 6, 850, '80+ Gold', 'Fully modular', 'SFX', 'Yes'),
    $r('Corsair', 'SF750 750W SFX Power Supply', 799, null, 7, 750, '80+ Platinum', 'Fully modular', 'SFX', 'Yes'),
    $r('Thermaltake', 'Toughpower GF3 1000W Power Supply', 799, null, 9, 1000, '80+ Gold', 'Fully modular', 'ATX', 'Yes'),
    $r('Corsair', 'HX1500i 1500W Power Supply', 1599, null, 3, 1500, '80+ Platinum', 'Fully modular', 'ATX', 'Yes'),
    $r('ASUS', 'ROG Thor 1200W Platinum II Power Supply', 1799, null, 4, 1200, '80+ Platinum', 'Fully modular', 'ATX', 'Yes'),
];
