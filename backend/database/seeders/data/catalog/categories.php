<?php

/*
| Category structure and the specification definitions each category supports.
| Flags: f = filterable, c = show on product card, o = optional (not required).
| `builder_slot` marks the categories the PC builder uses (null = not a PC component).
*/

$s = fn (string $key, string $label, string $type, ?string $unit = null, ?array $options = null, string $flags = '') => [
    'key' => $key,
    'label' => $label,
    'type' => $type,
    'unit' => $unit,
    'options' => $options,
    'is_filterable' => str_contains($flags, 'f'),
    'show_on_card' => str_contains($flags, 'c'),
    'is_required' => ! str_contains($flags, 'o'),
];

$yesNo = ['Yes', 'No'];
$sockets = ['AM4', 'AM5', 'LGA1700', 'LGA1851'];
$memory = ['DDR4', 'DDR5'];
$formFactors = ['ATX', 'Micro ATX', 'Mini ITX', 'E-ATX'];

return [
    ['slug' => 'cpu', 'name' => 'CPU', 'builder_slot' => 'cpu', 'description' => 'Processors from AMD and Intel.', 'specs' => [
        $s('socket', 'Socket', 'enum', null, $sockets, 'fc'),
        $s('cores', 'Cores', 'number', null, null, 'fc'),
        $s('threads', 'Threads', 'number', null, null, 'c'),
        $s('base_clock_ghz', 'Base clock', 'number', 'GHz'),
        $s('boost_clock_ghz', 'Boost clock', 'number', 'GHz'),
        $s('tdp_watts', 'TDP', 'number', 'W'),
        $s('integrated_graphics', 'Integrated graphics', 'enum', null, $yesNo, 'f'),
        $s('memory_types', 'Supported memory', 'list', null, $memory, 'f'),
        $s('cooler_included', 'Cooler included', 'enum', null, $yesNo, 'f'),
    ]],
    ['slug' => 'motherboard', 'name' => 'Motherboard', 'builder_slot' => 'motherboard', 'description' => 'Motherboards for AMD and Intel platforms.', 'specs' => [
        $s('socket', 'Socket', 'enum', null, $sockets, 'fc'),
        $s('chipset', 'Chipset', 'enum', null, ['B450', 'B550', 'X570', 'B650', 'X670E', 'B850', 'X870', 'H610', 'B760', 'Z790', 'Z890'], 'f'),
        $s('form_factor', 'Form factor', 'enum', null, $formFactors, 'fc'),
        $s('ram_type', 'Memory type', 'enum', null, $memory, 'fc'),
        $s('ram_slots', 'Memory slots', 'number'),
        $s('max_ram_gb', 'Max memory', 'number', 'GB'),
        $s('m2_slots', 'M.2 slots', 'number'),
        $s('wifi', 'Wi-Fi', 'enum', null, $yesNo, 'f'),
    ]],
    ['slug' => 'ram', 'name' => 'RAM', 'builder_slot' => 'ram', 'description' => 'Desktop memory kits.', 'specs' => [
        $s('ram_type', 'Type', 'enum', null, $memory, 'fc'),
        $s('capacity_gb', 'Total capacity', 'number', 'GB', null, 'fc'),
        $s('modules', 'Modules in kit', 'number'),
        $s('speed_mhz', 'Speed', 'number', 'MHz', null, 'fc'),
        $s('cas_latency', 'CAS latency', 'number', 'CL'),
        $s('profile', 'Profile', 'enum', null, ['XMP', 'EXPO', 'None'], 'f'),
    ]],
    ['slug' => 'gpu', 'name' => 'GPU', 'builder_slot' => 'gpu', 'description' => 'Graphics cards.', 'specs' => [
        $s('gpu_brand', 'GPU maker', 'enum', null, ['NVIDIA', 'AMD', 'Intel'], 'f'),
        $s('gpu_chipset', 'Chipset', 'string', null, null, 'c'),
        $s('vram_gb', 'Video memory', 'number', 'GB', null, 'fc'),
        $s('memory_type', 'Memory type', 'enum', null, ['GDDR6', 'GDDR6X', 'GDDR7']),
        $s('length_mm', 'Card length', 'number', 'mm', null, 'c'),
        $s('tdp_watts', 'Board power', 'number', 'W'),
        $s('recommended_psu_watts', 'Recommended PSU', 'number', 'W'),
        $s('power_connectors', 'Power connectors', 'string'),
        $s('interface', 'Interface', 'enum', null, ['PCIe 4.0 x8', 'PCIe 4.0 x16', 'PCIe 5.0 x8', 'PCIe 5.0 x16']),
    ]],
    ['slug' => 'storage', 'name' => 'Storage', 'builder_slot' => 'storage', 'description' => 'SSDs and hard drives.', 'specs' => [
        $s('storage_type', 'Type', 'enum', null, ['NVMe SSD', 'SATA SSD', 'HDD'], 'fc'),
        $s('capacity_gb', 'Capacity', 'number', 'GB', null, 'fc'),
        $s('interface', 'Interface', 'enum', null, ['PCIe 3.0 x4', 'PCIe 4.0 x4', 'PCIe 5.0 x4', 'SATA III'], 'f'),
        $s('form_factor', 'Form factor', 'enum', null, ['M.2 2280', '2.5"', '3.5"']),
        $s('read_mbps', 'Read speed', 'number', 'MB/s', null, 'c'),
        $s('write_mbps', 'Write speed', 'number', 'MB/s'),
    ]],
    ['slug' => 'psu', 'name' => 'PSU', 'builder_slot' => 'psu', 'description' => 'Power supply units.', 'specs' => [
        $s('wattage', 'Wattage', 'number', 'W', null, 'fc'),
        $s('efficiency', 'Efficiency', 'enum', null, ['80+ Bronze', '80+ Gold', '80+ Platinum'], 'fc'),
        $s('modularity', 'Modularity', 'enum', null, ['Non-modular', 'Semi-modular', 'Fully modular'], 'fc'),
        $s('form_factor', 'Form factor', 'enum', null, ['ATX', 'SFX'], 'f'),
        $s('pcie5_connector', '12V-2x6 connector', 'enum', null, $yesNo),
    ]],
    ['slug' => 'pc-case', 'name' => 'PC Case', 'builder_slot' => 'case', 'description' => 'PC cases from compact to full tower.', 'specs' => [
        $s('supported_form_factors', 'Motherboard support', 'list', null, $formFactors, 'fc'),
        $s('gpu_max_length_mm', 'Max GPU length', 'number', 'mm', null, 'c'),
        $s('cpu_cooler_max_height_mm', 'Max CPU cooler height', 'number', 'mm'),
        $s('case_type', 'Case type', 'enum', null, ['Mini Tower', 'Mid Tower', 'Full Tower', 'SFF'], 'fc'),
        $s('psu_form_factor', 'PSU form factor', 'enum', null, ['ATX', 'SFX']),
        $s('side_panel', 'Side panel', 'enum', null, ['Tempered Glass', 'Mesh', 'Acrylic', 'Solid'], 'f'),
        $s('fans_included', 'Fans included', 'number'),
    ]],
    ['slug' => 'cpu-cooler', 'name' => 'CPU Cooler', 'builder_slot' => 'cooler', 'description' => 'Air coolers and liquid coolers.', 'specs' => [
        $s('cooler_type', 'Type', 'enum', null, ['Air', 'AIO Liquid'], 'fc'),
        $s('height_mm', 'Height', 'number', 'mm', null, 'co'),
        $s('radiator_size_mm', 'Radiator size', 'number', 'mm', null, 'cfo'),
        $s('supported_sockets', 'Supported sockets', 'list', null, $sockets, 'f'),
        $s('tdp_rating_watts', 'Cooling capacity', 'number', 'W'),
    ]],
    ['slug' => 'case-fan', 'name' => 'Case Fan', 'builder_slot' => null, 'description' => 'Case and radiator fans.', 'specs' => [
        $s('size_mm', 'Size', 'number', 'mm', null, 'fc'),
        $s('rpm', 'Max speed', 'number', 'RPM'),
        $s('airflow_cfm', 'Airflow', 'number', 'CFM'),
        $s('rgb', 'RGB lighting', 'enum', null, $yesNo, 'fc'),
        $s('pack_quantity', 'Fans in pack', 'number', null, null, 'c'),
    ]],
    ['slug' => 'monitor', 'name' => 'Monitor', 'builder_slot' => null, 'description' => 'Gaming and productivity monitors.', 'specs' => [
        $s('size_inch', 'Screen size', 'number', 'inch', null, 'fc'),
        $s('resolution', 'Resolution', 'enum', null, ['1920x1080', '2560x1440', '3440x1440', '3840x2160'], 'fc'),
        $s('refresh_rate_hz', 'Refresh rate', 'number', 'Hz', null, 'fc'),
        $s('panel_type', 'Panel', 'enum', null, ['IPS', 'VA', 'TN', 'OLED'], 'f'),
        $s('response_time_ms', 'Response time', 'number', 'ms'),
    ]],
    ['slug' => 'keyboard', 'name' => 'Keyboard', 'builder_slot' => null, 'description' => 'Mechanical and membrane keyboards.', 'specs' => [
        $s('keyboard_type', 'Type', 'enum', null, ['Mechanical', 'Membrane'], 'fc'),
        $s('layout', 'Layout', 'enum', null, ['Full-size', 'TKL', '75%', '65%', '60%'], 'fc'),
        $s('switch_type', 'Switches', 'string'),
        $s('connectivity', 'Connectivity', 'enum', null, ['Wired', 'Wireless', 'Wired + Wireless'], 'fc'),
    ]],
    ['slug' => 'mouse', 'name' => 'Mouse', 'builder_slot' => null, 'description' => 'Gaming and office mice.', 'specs' => [
        $s('max_dpi', 'Max DPI', 'number', 'DPI', null, 'c'),
        $s('connectivity', 'Connectivity', 'enum', null, ['Wired', 'Wireless'], 'fc'),
        $s('weight_g', 'Weight', 'number', 'g', null, 'c'),
        $s('buttons', 'Buttons', 'number'),
    ]],
    ['slug' => 'headset', 'name' => 'Headset', 'builder_slot' => null, 'description' => 'Gaming headsets.', 'specs' => [
        $s('headset_type', 'Type', 'enum', null, ['Wired', 'Wireless'], 'fc'),
        $s('driver_mm', 'Driver size', 'number', 'mm', null, 'c'),
        $s('microphone', 'Microphone', 'enum', null, ['Detachable', 'Retractable', 'Built-in'], 'c'),
    ]],
    ['slug' => 'accessories', 'name' => 'Accessories', 'builder_slot' => null, 'description' => 'Thermal paste, cables and tools.', 'specs' => [
        $s('accessory_type', 'Type', 'enum', null, ['Thermal Paste', 'Cable Kit', 'Cable Management', 'GPU Support', 'Tools'], 'fc'),
    ]],
];
