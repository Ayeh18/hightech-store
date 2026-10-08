<?php

// brand, name, price RM, discount RM, stock, type, capacity GB, interface, form factor, read MB/s, write MB/s
$r = fn ($brand, $name, $price, $disc, $stock, $type, $cap, $if, $ff, $read, $write) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 60, 'weight' => 10,
    'specs' => [
        'storage_type' => $type, 'capacity_gb' => $cap, 'interface' => $if, 'form_factor' => $ff,
        'read_mbps' => $read, 'write_mbps' => $write,
    ],
];

return [
    $r('Kingston', 'NV3 500GB NVMe SSD', 169, null, 55, 'NVMe SSD', 500, 'PCIe 4.0 x4', 'M.2 2280', 4800, 2800),
    $r('WD', 'Blue SN580 1TB NVMe SSD', 269, 249, 48, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 4150, 4150),
    $r('Crucial', 'P3 Plus 1TB NVMe SSD', 289, null, 42, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 5000, 3600),
    $r('TeamGroup', 'MP44 1TB NVMe SSD', 299, null, 33, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 7000, 6500),
    $r('Kingston', 'KC3000 1TB NVMe SSD', 379, null, 28, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 7000, 6000),
    $r('Samsung', '990 PRO 1TB NVMe SSD', 459, null, 30, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 7450, 6900),
    $r('Lexar', 'NM790 2TB NVMe SSD', 599, null, 21, 'NVMe SSD', 2000, 'PCIe 4.0 x4', 'M.2 2280', 7400, 6500),
    $r('Crucial', 'T500 2TB NVMe SSD', 749, null, 14, 'NVMe SSD', 2000, 'PCIe 4.0 x4', 'M.2 2280', 7400, 7000),
    $r('Samsung', '9100 PRO 1TB NVMe SSD', 749, null, 10, 'NVMe SSD', 1000, 'PCIe 5.0 x4', 'M.2 2280', 14700, 13300),
    $r('WD', 'Black SN850X 2TB NVMe SSD', 799, 749, 16, 'NVMe SSD', 2000, 'PCIe 4.0 x4', 'M.2 2280', 7300, 6600),
    $r('Crucial', 'T705 2TB NVMe SSD', 1599, null, 4, 'NVMe SSD', 2000, 'PCIe 5.0 x4', 'M.2 2280', 14500, 12700),
    $r('Kingston', 'A400 480GB SATA SSD', 129, null, 60, 'SATA SSD', 480, 'SATA III', '2.5"', 500, 450),
    $r('Crucial', 'MX500 500GB SATA SSD', 229, null, 35, 'SATA SSD', 500, 'SATA III', '2.5"', 560, 510),
    $r('Samsung', '870 EVO 1TB SATA SSD', 389, null, 24, 'SATA SSD', 1000, 'SATA III', '2.5"', 560, 530),
    $r('Toshiba', 'P300 1TB 7200RPM Hard Drive', 219, null, 30, 'HDD', 1000, 'SATA III', '3.5"', 200, 200),
    $r('Seagate', 'BarraCuda 2TB 7200RPM Hard Drive', 279, null, 32, 'HDD', 2000, 'SATA III', '3.5"', 220, 220),
    $r('WD', 'Blue 4TB 5400RPM Hard Drive', 449, null, 18, 'HDD', 4000, 'SATA III', '3.5"', 180, 180),
    $r('Seagate', 'IronWolf 4TB NAS Hard Drive', 479, null, 15, 'HDD', 4000, 'SATA III', '3.5"', 210, 210),
];
