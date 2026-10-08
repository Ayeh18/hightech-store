<?php

// brand, name, price RM, discount RM, stock, type, total GB, modules, speed MHz, CAS, profile
$r = fn ($brand, $name, $price, $disc, $stock, $type, $cap, $modules, $speed, $cas, $profile) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 120, 'weight' => 60,
    'specs' => [
        'ram_type' => $type, 'capacity_gb' => $cap, 'modules' => $modules, 'speed_mhz' => $speed,
        'cas_latency' => $cas, 'profile' => $profile,
    ],
];

return [
    $r('Crucial', '16GB (1x16GB) DDR4-3200 CL22 Desktop Memory', 129, null, 50, 'DDR4', 16, 1, 3200, 22, 'None'),
    $r('Kingston', 'Fury Beast 16GB (2x8GB) DDR4-3200 CL16', 159, 149, 45, 'DDR4', 16, 2, 3200, 16, 'XMP'),
    $r('G.Skill', 'Ripjaws V 16GB (2x8GB) DDR4-3600 CL18', 169, null, 38, 'DDR4', 16, 2, 3600, 18, 'XMP'),
    $r('Corsair', 'Vengeance LPX 32GB (2x16GB) DDR4-3200 CL16', 289, null, 30, 'DDR4', 32, 2, 3200, 16, 'XMP'),
    $r('TeamGroup', 'T-Force Vulcan Z 32GB (2x16GB) DDR4-3600 CL18', 299, null, 22, 'DDR4', 32, 2, 3600, 18, 'XMP'),
    $r('Corsair', 'Vengeance RGB Pro 32GB (2x16GB) DDR4-3600 CL18', 359, null, 16, 'DDR4', 32, 2, 3600, 18, 'XMP'),
    $r('Kingston', 'Fury Beast 16GB (2x8GB) DDR5-5600 CL40', 249, null, 40, 'DDR5', 16, 2, 5600, 40, 'XMP'),
    $r('Crucial', 'Pro 32GB (2x16GB) DDR5-5600 CL46', 369, null, 28, 'DDR5', 32, 2, 5600, 46, 'XMP'),
    $r('TeamGroup', 'T-Create Expert 32GB (2x16GB) DDR5-6000 CL38', 399, null, 20, 'DDR5', 32, 2, 6000, 38, 'XMP'),
    $r('Corsair', 'Vengeance 32GB (2x16GB) DDR5-6000 CL30 EXPO', 429, 399, 26, 'DDR5', 32, 2, 6000, 30, 'EXPO'),
    $r('G.Skill', 'Flare X5 32GB (2x16GB) DDR5-6000 CL30 EXPO', 459, null, 19, 'DDR5', 32, 2, 6000, 30, 'EXPO'),
    $r('G.Skill', 'Trident Z5 RGB 32GB (2x16GB) DDR5-6000 CL30', 499, null, 17, 'DDR5', 32, 2, 6000, 30, 'XMP'),
    $r('Kingston', 'Fury Renegade 32GB (2x16GB) DDR5-6400 CL32', 549, null, 11, 'DDR5', 32, 2, 6400, 32, 'XMP'),
    $r('Corsair', 'Vengeance 64GB (2x32GB) DDR5-6000 CL30 EXPO', 899, null, 8, 'DDR5', 64, 2, 6000, 30, 'EXPO'),
    $r('Corsair', 'Dominator Titanium 64GB (2x32GB) DDR5-6000 CL30', 1199, null, 5, 'DDR5', 64, 2, 6000, 30, 'XMP'),
];
