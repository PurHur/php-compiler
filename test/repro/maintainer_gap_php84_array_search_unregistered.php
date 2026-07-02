<?php

declare(strict_types=1);

foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists({$fn}) false on " . PHP_VERSION . "\n";
        exit(1);
    }
}

echo "ok\n";
