<?php
// Repro #20360 — function_exists/method_exists/property_exists(null) TypeError under PROFILE=8.4
foreach ([
    'function_exists' => fn () => function_exists(null),
    'method_exists' => fn () => method_exists(stdClass::class, null),
    'property_exists' => fn () => property_exists(new stdClass(), null),
    'class_exists' => fn () => class_exists(null),
] as $name => $fn) {
    try {
        var_export($fn());
        echo " COERCED {$name}\n";
    } catch (Throwable $e) {
        echo get_class($e), " {$name}\n";
    }
}
