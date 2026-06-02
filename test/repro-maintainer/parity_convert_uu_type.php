<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: convert_uuencode()/convert_uudecode() TypeError parity.
 *
 * php-src: ext/standard/uuencode.c — PHP_FUNCTION(convert_uuencode), PHP_FUNCTION(convert_uudecode)
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

probe('convert_uuencode_array', static fn () => convert_uuencode([]));
probe('convert_uudecode_array', static fn () => convert_uudecode([]));
