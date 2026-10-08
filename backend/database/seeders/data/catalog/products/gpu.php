<?php

// brand, name, price RM, discount RM, stock, GPU maker, chipset, VRAM GB, memory type, length mm, board W, recommended PSU W, connectors, interface
$r = fn ($brand, $name, $price, $disc, $stock, $maker, $chip, $vram, $mem, $len, $tdp, $psu, $conn, $if) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 36, 'weight' => 1100,
    'specs' => [
        'gpu_brand' => $maker, 'gpu_chipset' => $chip, 'vram_gb' => $vram, 'memory_type' => $mem, 'length_mm' => $len,
        'tdp_watts' => $tdp, 'recommended_psu_watts' => $psu, 'power_connectors' => $conn, 'interface' => $if,
    ],
];

return [
    $r('Gigabyte', 'GeForce RTX 3050 Windforce OC 6GB', 899, null, 20, 'NVIDIA', 'GeForce RTX 3050', 6, 'GDDR6', 209, 70, 450, 'No external power', 'PCIe 4.0 x8'),
    $r('PowerColor', 'Reaper Radeon RX 7600 8GB', 999, null, 24, 'AMD', 'Radeon RX 7600', 8, 'GDDR6', 240, 165, 550, '1x 8-pin', 'PCIe 4.0 x8'),
    $r('ASRock', 'Arc B580 Challenger OC 12GB', 1199, null, 14, 'Intel', 'Arc B580', 12, 'GDDR6', 272, 190, 600, '1x 8-pin', 'PCIe 4.0 x8'),
    $r('Zotac', 'Gaming GeForce RTX 4060 Twin Edge 8GB', 1299, 1199, 30, 'NVIDIA', 'GeForce RTX 4060', 8, 'GDDR6', 224, 115, 550, '1x 8-pin', 'PCIe 4.0 x8'),
    $r('ASUS', 'Dual GeForce RTX 5060 OC 8GB', 1499, null, 26, 'NVIDIA', 'GeForce RTX 5060', 8, 'GDDR7', 229, 145, 550, '1x 8-pin', 'PCIe 5.0 x8'),
    $r('Sapphire', 'Pulse Radeon RX 9060 XT 16GB', 1799, null, 12, 'AMD', 'Radeon RX 9060 XT', 16, 'GDDR6', 240, 160, 550, '1x 8-pin', 'PCIe 5.0 x16'),
    $r('MSI', 'GeForce RTX 5060 Ti 16GB Gaming Trio OC', 2299, null, 9, 'NVIDIA', 'GeForce RTX 5060 Ti', 16, 'GDDR7', 307, 180, 650, '1x 8-pin', 'PCIe 5.0 x8'),
    $r('Sapphire', 'Pulse Radeon RX 7800 XT 16GB', 2299, 2099, 10, 'AMD', 'Radeon RX 7800 XT', 16, 'GDDR6', 280, 263, 700, '2x 8-pin', 'PCIe 4.0 x16'),
    $r('Gigabyte', 'GeForce RTX 5070 Windforce OC 12GB', 2599, null, 13, 'NVIDIA', 'GeForce RTX 5070', 12, 'GDDR7', 261, 250, 650, '1x 12V-2x6', 'PCIe 5.0 x16'),
    $r('PNY', 'GeForce RTX 4070 SUPER 12GB Verto', 2799, null, 8, 'NVIDIA', 'GeForce RTX 4070 SUPER', 12, 'GDDR6X', 267, 220, 650, '1x 12VHPWR', 'PCIe 4.0 x16'),
    $r('XFX', 'Swift Radeon RX 9070 XT 16GB', 3499, null, 7, 'AMD', 'Radeon RX 9070 XT', 16, 'GDDR6', 330, 304, 750, '2x 8-pin', 'PCIe 5.0 x16'),
    $r('ASUS', 'TUF Gaming GeForce RTX 5070 Ti 16GB', 4199, null, 6, 'NVIDIA', 'GeForce RTX 5070 Ti', 16, 'GDDR7', 329, 300, 750, '1x 12V-2x6', 'PCIe 5.0 x16'),
    $r('MSI', 'GeForce RTX 5080 16GB Ventus 3X OC', 6299, null, 4, 'NVIDIA', 'GeForce RTX 5080', 16, 'GDDR7', 304, 360, 850, '1x 12V-2x6', 'PCIe 5.0 x16'),
    $r('ASUS', 'ROG Astral GeForce RTX 5090 32GB', 14999, null, 2, 'NVIDIA', 'GeForce RTX 5090', 32, 'GDDR7', 358, 575, 1000, '1x 12V-2x6', 'PCIe 5.0 x16'),
];
