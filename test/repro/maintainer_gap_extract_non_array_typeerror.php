<?php

declare(strict_types=1);

try {
    extract(1);
    echo "fail: no TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('extract(): Argument #1 ($array) must be of type array, int given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
