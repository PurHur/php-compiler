<?php

declare(strict_types=1);

try {
    array_flip(new stdClass());
    echo "fail: no TypeError\n";
    exit(1);
} catch (TypeError $e) {
    if ('array_flip(): Argument #1 ($array) must be of type array, stdClass given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
