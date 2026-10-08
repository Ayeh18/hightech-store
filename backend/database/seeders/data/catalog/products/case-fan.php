<?php

// brand, name, price RM, discount RM, stock, size mm, rpm, airflow CFM, RGB, pack quantity
$r = fn ($brand, $name, $price, $disc, $stock, $size, $rpm, $cfm, $rgb, $qty) => [
    'brand' => $brand, 'name' => $name, 'price' => $price, 'discount' => $disc, 'stock' => $stock,
    'warranty' => 36, 'weight' => 150 * $qty,
    'specs' => ['size_mm' => $size, 'rpm' => $rpm, 'airflow_cfm' => $cfm, 'rgb' => $rgb, 'pack_quantity' => $qty],
];

return [
    $r('Arctic', 'P12 PWM PST 120mm Case Fan (5-pack)', 79, null, 50, 120, 1800, 56.3, 'No', 5),
    $r('Corsair', 'AF120 Elite 120mm Case Fan', 69, null, 30, 120, 2000, 52.2, 'No', 1),
    $r('Deepcool', 'FC120 ARGB 120mm Case Fan (3-pack)', 99, null, 34, 120, 1800, 56.0, 'Yes', 3),
    $r('be quiet!', 'Light Wings 140mm PWM Case Fan (3-pack)', 189, null, 12, 140, 1600, 77.4, 'Yes', 3),
    $r('Noctua', 'NF-A12x25 PWM 120mm Case Fan', 119, null, 22, 120, 2000, 60.1, 'No', 1),
    $r('Lian Li', 'UNI FAN SL-Infinity 120mm Fan (3-pack)', 329, 299, 10, 120, 2100, 61.3, 'Yes', 3),
    $r('Noctua', 'NF-A14 PWM 140mm Case Fan', 99, null, 18, 140, 1500, 82.5, 'No', 1),
];
