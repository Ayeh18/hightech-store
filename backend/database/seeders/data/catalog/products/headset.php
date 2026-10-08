<?php

// brand, name, price RM, discount RM, stock, type, driver mm, microphone
$r = fn ($brand, $name, $price, $disc, $stock, $type, $driver, $mic) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 12, 'weight' => 300,
    'specs' => ['headset_type' => $type, 'driver_mm' => $driver, 'microphone' => $mic],
];

return [
    $r('ASUS', 'TUF Gaming H3 Wired Headset', 169, null, 25, 'Wired', 50, 'Built-in'),
    $r('HyperX', 'Cloud Stinger 2 Wired Headset', 229, null, 20, 'Wired', 50, 'Retractable'),
    $r('Razer', 'BlackShark V2 X Wired Headset', 229, 199, 18, 'Wired', 50, 'Detachable'),
    $r('Logitech', 'G435 Lightspeed Wireless Headset', 249, null, 22, 'Wireless', 40, 'Built-in'),
    $r('SteelSeries', 'Arctis Nova 1 Wired Headset', 279, null, 14, 'Wired', 40, 'Retractable'),
    $r('Corsair', 'HS55 Wireless Headset', 299, null, 11, 'Wireless', 50, 'Detachable'),
];
