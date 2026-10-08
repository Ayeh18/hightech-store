<?php

// brand, name, price RM, discount RM, stock, type, height mm|null, radiator mm|null, sockets, cooling capacity W
$r = fn ($brand, $name, $price, $disc, $stock, $type, $height, $radiator, $sockets, $watts) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 36, 'weight' => $type === 'Air' ? 900 : 1500,
    'specs' => [
        'cooler_type' => $type, 'height_mm' => $height, 'radiator_size_mm' => $radiator,
        'supported_sockets' => $sockets, 'tdp_rating_watts' => $watts,
    ],
];

$all = ['AM4', 'AM5', 'LGA1700', 'LGA1851'];

return [
    $r('Thermalright', 'Assassin X 120 R SE CPU Air Cooler', 59, null, 40, 'Air', 148, null, $all, 180),
    $r('Deepcool', 'AK400 CPU Air Cooler', 99, null, 45, 'Air', 155, null, $all, 220),
    $r('Cooler Master', 'Hyper 212 Black Edition CPU Air Cooler', 119, null, 28, 'Air', 159, null, $all, 180),
    $r('Thermalright', 'Peerless Assassin 120 SE CPU Air Cooler', 139, 129, 36, 'Air', 155, null, $all, 260),
    $r('Noctua', 'NH-U12S redux CPU Air Cooler', 179, null, 15, 'Air', 158, null, $all, 200),
    $r('Deepcool', 'AK620 CPU Air Cooler', 219, null, 20, 'Air', 160, null, $all, 260),
    $r('be quiet!', 'Dark Rock Pro 5 CPU Air Cooler', 459, null, 8, 'Air', 168, null, $all, 270),
    $r('Noctua', 'NH-D15 G2 CPU Air Cooler', 599, null, 7, 'Air', 168, null, $all, 300),
    $r('Arctic', 'Liquid Freezer III 240 AIO Liquid Cooler', 289, null, 18, 'AIO Liquid', null, 240, $all, 300),
    $r('Deepcool', 'LT520 240mm AIO Liquid Cooler', 329, null, 14, 'AIO Liquid', null, 240, $all, 280),
    $r('Arctic', 'Liquid Freezer III 360 AIO Liquid Cooler', 369, 339, 16, 'AIO Liquid', null, 360, $all, 350),
    $r('Corsair', 'iCUE H100i Elite 240mm AIO Liquid Cooler', 549, null, 9, 'AIO Liquid', null, 240, $all, 300),
    $r('Lian Li', 'Galahad II Trinity 360mm AIO Liquid Cooler', 599, null, 7, 'AIO Liquid', null, 360, $all, 350),
    $r('NZXT', 'Kraken Elite 360 AIO Liquid Cooler', 1099, null, 4, 'AIO Liquid', null, 360, $all, 400),
];
