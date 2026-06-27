<?php

declare(strict_types=1);

try {
    array_map(1, [1]);
    echo "fail: array_map(1, [1]) uncaught\n";
    exit(1);
} catch (\TypeError $e) {
    if (!str_contains($e->getMessage(), 'valid callback')) {
        echo 'fail: unexpected TypeError: '.$e->getMessage()."\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo 'fail: array_map(1, [1]) threw '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}

echo "ok\n";
