<?php

declare(strict_types=1);

try {
    unserialize(null);
    echo "fail: unserialize(null) expected TypeError under strict_types\n";
    exit(1);
} catch (TypeError $e) {
    if ('unserialize(): Argument #1 ($data) must be of type string, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
