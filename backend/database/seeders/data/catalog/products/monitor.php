<?php

// brand, name, price RM, discount RM, stock, size inch, resolution, refresh Hz, panel, response ms
$r = fn ($brand, $name, $price, $disc, $stock, $size, $res, $hz, $panel, $ms) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 36, 'weight' => 5000,
    'specs' => ['size_inch' => $size, 'resolution' => $res, 'refresh_rate_hz' => $hz, 'panel_type' => $panel, 'response_time_ms' => $ms],
];

return [
    $r('AOC', '24G4XE 24" Full HD 180Hz Gaming Monitor', 449, null, 20, 24, '1920x1080', 180, 'IPS', 1),
    $r('LG', '24GS60F 24" Full HD 180Hz Gaming Monitor', 599, 549, 18, 24, '1920x1080', 180, 'IPS', 1),
    $r('ASUS', 'TUF Gaming VG27AQ3A 27" QHD 180Hz Monitor', 899, null, 14, 27, '2560x1440', 180, 'IPS', 1),
    $r('Samsung', 'Odyssey G5 G50D 27" QHD 180Hz Monitor', 949, null, 11, 27, '2560x1440', 180, 'IPS', 1),
    $r('MSI', 'MAG 274QRF-QD 27" QHD 165Hz Monitor', 999, null, 9, 27, '2560x1440', 165, 'IPS', 1),
    $r('Gigabyte', 'M27Q X 27" QHD 240Hz Gaming Monitor', 1099, null, 8, 27, '2560x1440', 240, 'IPS', 1),
    $r('LG', 'UltraGear 27GP850-B 27" QHD Nano IPS Monitor', 1199, null, 7, 27, '2560x1440', 180, 'IPS', 1),
    $r('LG', '27UP850K 27" 4K UHD Monitor', 1599, null, 6, 27, '3840x2160', 60, 'IPS', 5),
    $r('Samsung', 'Odyssey OLED G6 27" QHD 360Hz Monitor', 3499, null, 3, 27, '2560x1440', 360, 'OLED', 0.03),
    $r('Alienware', 'AW3425DW 34" Curved QD-OLED Monitor', 3299, null, 3, 34, '3440x1440', 165, 'OLED', 0.03),
];
