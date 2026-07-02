<?php

declare(strict_types=1);

foreach (['str_increment', 'str_decrement', 'json_validate'] as $fn) {
    if (!function_exists($fn)) {
        echo "fail: function_exists({$fn}) false on " . PHP_VERSION . "\n";
        exit(1);
    }
}

echo "ok\n";
