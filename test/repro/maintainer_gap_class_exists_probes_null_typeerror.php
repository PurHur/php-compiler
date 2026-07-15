<?php
// #19223 — interface_exists/trait_exists/enum_exists/class_exists(null) TypeError under PROFILE=8.4
foreach (['class_exists', 'interface_exists', 'trait_exists', 'enum_exists'] as $fn) {
    try {
        $r = $fn(null);
        echo "$fn: returned ";
        var_export($r);
        echo "\n";
    } catch (Throwable $e) {
        echo "$fn: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
