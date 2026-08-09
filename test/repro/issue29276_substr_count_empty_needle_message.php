<?php

declare(strict_types=1);

/**
 * #29276 — substr_count() empty needle ValueError must match Zend: "must not be empty"
 * (php-src ext/standard/string.c PHP_FUNCTION(substr_count)).
 */
$expected = 'substr_count(): Argument #2 ($needle) must not be empty';

try {
    substr_count('abc', '');
    fwrite(STDERR, "fail: substr_count empty needle expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'empty:', $e->getMessage(), "\n";
}

echo "ok\n";
