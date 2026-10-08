<?php

// brand, name, price RM, discount RM, stock, socket, chipset, form factor, memory, slots, max GB, M.2 slots, wifi
$r = fn ($brand, $name, $price, $disc, $stock, $socket, $chipset, $ff, $ram, $slots, $max, $m2, $wifi) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 36, 'weight' => 1200,
    'specs' => [
        'socket' => $socket, 'chipset' => $chipset, 'form_factor' => $ff, 'ram_type' => $ram,
        'ram_slots' => $slots, 'max_ram_gb' => $max, 'm2_slots' => $m2, 'wifi' => $wifi,
    ],
];

return [
    $r('Gigabyte', 'B450M DS3H V2 Motherboard', 329, null, 25, 'AM4', 'B450', 'Micro ATX', 'DDR4', 4, 64, 1, 'No'),
    $r('MSI', 'B550-A PRO Motherboard', 499, null, 20, 'AM4', 'B550', 'ATX', 'DDR4', 4, 128, 2, 'No'),
    $r('ASUS', 'TUF Gaming B550M-Plus WiFi II Motherboard', 559, 519, 18, 'AM4', 'B550', 'Micro ATX', 'DDR4', 4, 128, 2, 'Yes'),
    $r('ASRock', 'B650M Pro RS Motherboard', 579, null, 22, 'AM5', 'B650', 'Micro ATX', 'DDR5', 4, 192, 2, 'No'),
    $r('Gigabyte', 'B650M DS3H Motherboard', 599, null, 24, 'AM5', 'B650', 'Micro ATX', 'DDR5', 4, 192, 2, 'No'),
    $r('ASUS', 'TUF Gaming B650M-E WiFi Motherboard', 799, null, 16, 'AM5', 'B650', 'Micro ATX', 'DDR5', 4, 192, 2, 'Yes'),
    $r('MSI', 'MAG B650 Tomahawk WiFi Motherboard', 999, 949, 14, 'AM5', 'B650', 'ATX', 'DDR5', 4, 192, 3, 'Yes'),
    $r('ASRock', 'B650I Lightning WiFi Motherboard', 1099, null, 7, 'AM5', 'B650', 'Mini ITX', 'DDR5', 2, 96, 2, 'Yes'),
    $r('Gigabyte', 'X870 Eagle WiFi7 Motherboard', 1199, null, 10, 'AM5', 'X870', 'ATX', 'DDR5', 4, 256, 3, 'Yes'),
    $r('ASUS', 'ROG Strix B850-A Gaming WiFi Motherboard', 1299, null, 9, 'AM5', 'B850', 'ATX', 'DDR5', 4, 192, 3, 'Yes'),
    $r('ASUS', 'ROG Strix X670E-E Gaming WiFi Motherboard', 2199, null, 4, 'AM5', 'X670E', 'ATX', 'DDR5', 4, 192, 4, 'Yes'),
    $r('ASUS', 'PRIME H610M-K D4 Motherboard', 299, null, 30, 'LGA1700', 'H610', 'Micro ATX', 'DDR4', 2, 64, 1, 'No'),
    $r('MSI', 'PRO B760M-P DDR4 Motherboard', 429, null, 26, 'LGA1700', 'B760', 'Micro ATX', 'DDR4', 2, 64, 2, 'No'),
    $r('ASUS', 'PRIME B760M-A WiFi D4 Motherboard', 599, null, 15, 'LGA1700', 'B760', 'Micro ATX', 'DDR4', 4, 128, 2, 'Yes'),
    $r('Gigabyte', 'B760M Gaming X AX DDR5 Motherboard', 749, 699, 12, 'LGA1700', 'B760', 'Micro ATX', 'DDR5', 4, 192, 2, 'Yes'),
    $r('Gigabyte', 'B760 Aorus Elite AX Motherboard', 899, null, 11, 'LGA1700', 'B760', 'ATX', 'DDR5', 4, 192, 3, 'Yes'),
    $r('ASUS', 'TUF Gaming Z790-Plus WiFi D4 Motherboard', 1199, null, 6, 'LGA1700', 'Z790', 'ATX', 'DDR4', 4, 128, 4, 'Yes'),
    $r('MSI', 'MAG Z790 Tomahawk WiFi Motherboard', 1299, null, 8, 'LGA1700', 'Z790', 'ATX', 'DDR5', 4, 192, 4, 'Yes'),
    $r('MSI', 'PRO Z890-P WiFi Motherboard', 1099, null, 10, 'LGA1851', 'Z890', 'ATX', 'DDR5', 4, 256, 3, 'Yes'),
    $r('Gigabyte', 'Z890 Aorus Elite WiFi7 ICE Motherboard', 1499, null, 5, 'LGA1851', 'Z890', 'ATX', 'DDR5', 4, 256, 4, 'Yes'),
];
