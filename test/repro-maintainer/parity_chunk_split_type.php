<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: chunk_split() must throw TypeError for non-string $string.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
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

probe('chunk_split_array', static fn () => chunk_split([]));
probe('chunk_split_object', static fn () => chunk_split(new stdClass()));
