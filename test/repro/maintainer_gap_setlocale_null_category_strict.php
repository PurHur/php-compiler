<?php

/**
 * setlocale(null, 'C') under strict_types — Zend TypeError (no DEP).
 * php-src: ext/standard/string.c PHP_FUNCTION(setlocale)
 */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo 'ERR[', $errno, ']: ', $errstr, "\n";

    return true;
});

try {
    var_export(setlocale(null, 'C'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
