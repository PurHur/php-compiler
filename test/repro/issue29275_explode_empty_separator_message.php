<?php

declare(strict_types=1);

/**
 * #29275 — explode() empty separator ValueError must match Zend: "cannot be empty"
 * (php-src ext/standard/string.c PHP_FUNCTION(explode)).
 */
$expected = 'explode(): Argument #1 ($separator) cannot be empty';

try {
    explode('', 'a,b');
    fwrite(STDERR, "fail: explode(\"\") expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'empty:', $e->getMessage(), "\n";
}

echo "ok\n";
