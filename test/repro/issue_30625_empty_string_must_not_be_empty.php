<?php

declare(strict_types=1);

/**
 * #30625 — zend_argument_must_not_be_empty_error is "must not be empty" on Zend 8.4+
 * (php-src Zend/zend_API.c). PROFILE=8.2 keeps "cannot be empty".
 *
 * Run with PHP_COMPILER_PROFILE=8.4.
 */
$suffix = 'must not be empty';
$cases = [
    ['hash_init HMAC', static fn () => hash_init('sha256', HASH_HMAC, ''), 'hash_init(): Argument #3 ($key) '.$suffix.' when HMAC is requested'],
    ['explode', static fn () => explode('', 'a'), 'explode(): Argument #1 ($separator) '.$suffix],
    ['substr_count', static fn () => substr_count('aa', ''), 'substr_count(): Argument #2 ($needle) '.$suffix],
    ['ftok', static fn () => ftok('', 'a'), 'ftok(): Argument #1 ($filename) '.$suffix],
];

foreach ($cases as [$label, $fn, $expected]) {
    try {
        $fn();
        fwrite(STDERR, "fail: $label expected ValueError\n");
        exit(1);
    } catch (ValueError $e) {
        if ($expected !== $e->getMessage()) {
            fwrite(STDERR, "fail: $label got {$e->getMessage()}\n");
            exit(1);
        }
        echo $label, ': ', $e->getMessage(), "\n";
    }
}

echo "ok\n";
