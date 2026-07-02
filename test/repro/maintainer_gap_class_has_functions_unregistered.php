<?php

declare(strict_types=1);

foreach (['class_has_method', 'class_has_property', 'class_has_constant'] as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists({$fn}) false on " . PHP_VERSION . "\n";
        exit(1);
    }
}

echo "ok\n";
