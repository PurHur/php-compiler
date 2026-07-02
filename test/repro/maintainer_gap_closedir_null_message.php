<?php

declare(strict_types=1);

try {
    closedir(null);
    echo "fail: expected TypeError\n";
    exit(1);
} catch (\TypeError $e) {
    if ('No resource supplied' !== $e->getMessage()) {
        echo 'fail: wrong message: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
