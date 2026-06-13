<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: wordwrap() must throw TypeError for non-string $string (#4579).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
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

probe('wordwrap_array', static function (): void {
    wordwrap([]);
});
probe('wordwrap_object', static function (): void {
    wordwrap(new stdClass());
});
