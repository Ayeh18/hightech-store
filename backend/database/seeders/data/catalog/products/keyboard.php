<?php

// brand, name, price RM, discount RM, stock, type, layout, switches, connectivity
$r = fn ($brand, $name, $price, $disc, $stock, $type, $layout, $switch, $conn) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 12, 'weight' => 900,
    'specs' => ['keyboard_type' => $type, 'layout' => $layout, 'switch_type' => $switch, 'connectivity' => $conn],
];

return [
    $r('Logitech', 'K120 Wired Keyboard', 49, null, 80, 'Membrane', 'Full-size', 'Membrane', 'Wired'),
    $r('Royal Kludge', 'RK61 60% Mechanical Keyboard', 169, null, 30, 'Mechanical', '60%', 'RK Red (linear)', 'Wired + Wireless'),
    $r('Akko', '3068B Plus 65% Mechanical Keyboard', 349, 319, 22, 'Mechanical', '65%', 'Akko CS Jelly Pink (linear)', 'Wired + Wireless'),
    $r('Corsair', 'K70 Core Mechanical Keyboard', 399, null, 16, 'Mechanical', 'Full-size', 'Corsair Red (linear)', 'Wired'),
    $r('Razer', 'BlackWidow V4 X Mechanical Keyboard', 429, null, 14, 'Mechanical', 'Full-size', 'Razer Green (clicky)', 'Wired'),
    $r('Keychron', 'K2 Pro 75% Wireless Mechanical Keyboard', 549, null, 12, 'Mechanical', '75%', 'Gateron Pro Red (linear)', 'Wired + Wireless'),
    $r('Logitech', 'G Pro X TKL Lightspeed Keyboard', 749, null, 8, 'Mechanical', 'TKL', 'GX Brown (tactile)', 'Wireless'),
];
