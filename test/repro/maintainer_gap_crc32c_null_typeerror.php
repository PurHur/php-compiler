<?php

declare(strict_types=1);

if (!function_exists('crc32c')) {
    echo "skip: crc32c not on this profile\n";
    exit(0);
}

try {
    crc32c(null);
    echo "fail: expected TypeError\n";
    exit(1);
} catch (\TypeError $e) {
    $expected = 'crc32c(): Argument #1 ($string) must be of type string, null given';
    if ($expected !== $e->getMessage()) {
        echo 'fail: got ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok:TypeError\n";
} catch (\Throwable $e) {
    echo 'bad:', get_class($e), ':', $e->getMessage(), "\n";
    exit(1);
}
