<?php

declare(strict_types=1);

try {
    array_pad(null, 2, 0);
    echo "fail: no TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('array_pad(): Argument #1 ($array) must be of type array, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
