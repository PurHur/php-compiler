<?php

declare(strict_types=1);

try {
    array_intersect([1], null);
    echo "fail: no TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('array_intersect(): Argument #2 must be of type array, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
