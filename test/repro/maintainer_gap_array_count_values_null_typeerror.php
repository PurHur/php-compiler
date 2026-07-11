<?php

declare(strict_types=1);

try {
    array_count_values(null);
    echo "fail: no TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('array_count_values(): Argument #1 ($array) must be of type array, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
