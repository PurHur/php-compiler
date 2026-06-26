<?php

declare(strict_types=1);

try {
    http_build_query(null);
    echo "fail: no TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('http_build_query(): Argument #1 ($data) must be of type array, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
