<?php

declare(strict_types=1);

/**
 * Maintainer repro: array_find()/array_find_key() array:/callback: named parameters (#10077).
 *
 * php-src: ext/standard/basic_functions.stub.php — PHP 8.4 array_find family
 */

if (!function_exists('array_find')) {
    echo "fail: array_find() not registered — set PHP_COMPILER_PROFILE=8.4\n";
    exit(1);
}

$found = array_find(array: [1, 2, 3], callback: static fn (int $v): bool => $v > 1);
if (2 !== $found) {
    echo 'fail: array_find named returned ', var_export($found, true), "\n";
    exit(1);
}

$key = array_find_key(array: ['a' => 1, 'b' => 2], callback: static fn (int $v): bool => $v > 1);
if ('b' !== $key) {
    echo 'fail: array_find_key named returned ', var_export($key, true), "\n";
    exit(1);
}

echo "ok\n";
