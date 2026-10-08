<?php

// brand, name, price RM, discount RM, stock, max DPI, connectivity, weight g, buttons
$r = fn ($brand, $name, $price, $disc, $stock, $dpi, $conn, $weight, $buttons) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 24, 'weight' => 200,
    'specs' => ['max_dpi' => $dpi, 'connectivity' => $conn, 'weight_g' => $weight, 'buttons' => $buttons],
];

return [
    $r('SteelSeries', 'Rival 3 Gaming Mouse', 119, null, 40, 8500, 'Wired', 77, 6),
    $r('Logitech', 'G304 Lightspeed Wireless Mouse', 149, 129, 36, 12000, 'Wireless', 99, 6),
    $r('Glorious', 'Model O 2 Wireless Mouse', 399, null, 15, 26000, 'Wireless', 63, 6),
    $r('Razer', 'DeathAdder V3 Gaming Mouse', 429, null, 18, 30000, 'Wired', 59, 6),
    $r('Logitech', 'G Pro X Superlight 2 Wireless Mouse', 699, null, 12, 32000, 'Wireless', 60, 5),
    $r('Razer', 'Viper V3 Pro Wireless Mouse', 799, null, 9, 35000, 'Wireless', 54, 5),
];
