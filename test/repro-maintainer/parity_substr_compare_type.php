<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: substr_compare() TypeError + Zend error messages.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(substr_compare)
 */

function probe(string $label, callable $fn): void
{
    try {
        $r = $fn();
        echo "$label: ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

probe('substr_compare_array_haystack', static function (): void {
    substr_compare([], 'a', 0);
});
probe('substr_compare_array_needle', static function (): void {
    substr_compare('abc', [], 0);
});
probe('substr_compare_numeric_offset', static function (): void {
    substr_compare('abc', 'ab', '0', 2);
});
