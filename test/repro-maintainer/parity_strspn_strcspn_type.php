<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: strspn()/strcspn() must throw TypeError for non-string operands.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strspn), PHP_FUNCTION(strcspn)
 */

function probe(string $label, callable $fn): void
{
    try {
        $fn();
        echo "$label: ok (no exception)\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

probe('strspn_array_haystack', static fn () => strspn([], 'a'));
probe('strspn_array_mask', static fn () => strspn('a', []));
probe('strcspn_array_haystack', static fn () => strcspn([], 'a'));
probe('strcspn_array_mask', static fn () => strcspn('a', []));
