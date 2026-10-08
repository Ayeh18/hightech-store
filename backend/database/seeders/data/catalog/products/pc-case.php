<?php

// brand, name, price RM, discount RM, stock, motherboard support, max GPU mm, max cooler mm, type, PSU form factor, side panel, fans
$r = fn ($brand, $name, $price, $disc, $stock, $ff, $gpu, $cooler, $type, $psuFf, $panel, $fans) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 24, 'weight' => 7500,
    'specs' => [
        'supported_form_factors' => $ff, 'gpu_max_length_mm' => $gpu, 'cpu_cooler_max_height_mm' => $cooler,
        'case_type' => $type, 'psu_form_factor' => $psuFf, 'side_panel' => $panel, 'fans_included' => $fans,
    ],
];

$atx = ['ATX', 'Micro ATX', 'Mini ITX'];
$all = ['ATX', 'Micro ATX', 'Mini ITX', 'E-ATX'];
$matx = ['Micro ATX', 'Mini ITX'];

return [
    $r('Thermaltake', 'S100 TG Micro Tower Case', 189, null, 24, $matx, 330, 160, 'Mini Tower', 'ATX', 'Tempered Glass', 1),
    $r('Cooler Master', 'MasterBox Q300L Micro-ATX Case', 229, null, 20, $matx, 360, 159, 'Mini Tower', 'ATX', 'Acrylic', 1),
    $r('Montech', 'Air 903 Max Mid Tower Case', 299, 279, 26, $all, 400, 170, 'Mid Tower', 'ATX', 'Mesh', 4),
    $r('Deepcool', 'CH360 Micro-ATX Case', 339, null, 13, $matx, 320, 173, 'Mini Tower', 'ATX', 'Tempered Glass', 0),
    $r('Corsair', '4000D Airflow Mid Tower Case', 389, null, 22, $atx, 360, 170, 'Mid Tower', 'ATX', 'Tempered Glass', 2),
    $r('NZXT', 'H5 Flow Mid Tower Case', 399, null, 17, $atx, 365, 165, 'Mid Tower', 'ATX', 'Tempered Glass', 2),
    $r('Phanteks', 'Eclipse G360A Mid Tower Case', 399, null, 15, $atx, 400, 162, 'Mid Tower', 'ATX', 'Tempered Glass', 3),
    $r('Lian Li', 'Lancool 216 Mid Tower Case', 449, null, 19, $all, 392, 180, 'Mid Tower', 'ATX', 'Tempered Glass', 3),
    $r('Cooler Master', 'NR200P Mini-ITX Case', 599, null, 8, ['Mini ITX'], 330, 155, 'SFF', 'SFX', 'Tempered Glass', 2),
    $r('Fractal Design', 'North Mid Tower Case', 649, null, 10, $atx, 355, 169, 'Mid Tower', 'ATX', 'Tempered Glass', 2),
    $r('Corsair', '5000D Airflow Mid Tower Case', 649, 599, 12, $all, 420, 170, 'Mid Tower', 'ATX', 'Tempered Glass', 2),
    $r('Lian Li', 'O11 Dynamic EVO Mid Tower Case', 799, null, 9, $all, 426, 167, 'Mid Tower', 'ATX', 'Tempered Glass', 0),
    $r('Fractal Design', 'Terra Mini-ITX Case', 899, null, 4, ['Mini ITX'], 322, 77, 'SFF', 'SFX', 'Solid', 0),
];
