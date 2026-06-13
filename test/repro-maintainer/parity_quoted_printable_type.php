<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: quoted_printable_encode/decode TypeError for non-string operands.
 *
 * php-src: ext/standard/quot_print.c — PHP_FUNCTION(quoted_printable_encode/decode)
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

probe('quoted_printable_encode_array', static fn () => quoted_printable_encode([]));
probe('quoted_printable_decode_array', static fn () => quoted_printable_decode([]));
probe('quoted_printable_encode_object', static fn () => quoted_printable_encode(new stdClass()));
