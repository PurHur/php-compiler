<?php

/**
 * setlocale(null, 'C') without strict_types — Zend DEP+coerce; VM TypeError.
 * php-src: ext/standard/string.c PHP_FUNCTION(setlocale)
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    $label = match ($errno) {
        E_DEPRECATED => 'DEPRECATED',
        E_WARNING => 'WARNING',
        default => (string) $errno,
    };
    echo $label, ': ', $errstr, "\n";

    return true;
});

try {
    var_export(setlocale(null, 'C'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
