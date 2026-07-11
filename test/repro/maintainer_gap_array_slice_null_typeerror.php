<?php

declare(strict_types=1);

try {
    array_slice(null, 0);
    echo "fail: array_slice(null) expected TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('array_slice(): Argument #1 ($array) must be of type array, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
