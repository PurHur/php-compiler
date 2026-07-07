<?php

declare(strict_types=1);

/**
 * Issue #17133 — array_find()/array_find_key() null $callback must TypeError (ext/standard/array.c).
 */
if (getenv('PHP_COMPILER_PROFILE') !== '8.4') {
    putenv('PHP_COMPILER_PROFILE=8.4');
}

$expected = 'must be a valid callback, no array or string given';

foreach (['array_find', 'array_find_key'] as $fn) {
    try {
        $fn([1], null);
        fwrite(STDERR, "fail: {$fn}() expected TypeError\n");
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), $expected)) {
            fwrite(STDERR, "fail: {$fn}(): {$e->getMessage()}\n");
            exit(1);
        }
        echo $fn, ': ', get_class($e), "\n";
    }
}

echo "ok\n";
