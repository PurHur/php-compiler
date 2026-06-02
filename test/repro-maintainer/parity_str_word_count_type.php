<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: str_word_count() must throw TypeError for non-string $string.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_word_count)
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

probe('str_word_count_array', static fn () => str_word_count([]));
probe('str_word_count_object', static fn () => str_word_count(new stdClass()));
