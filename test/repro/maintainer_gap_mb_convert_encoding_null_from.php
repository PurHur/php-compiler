<?php

/**
 * mb_convert_encoding($str, $to, null) — Zend treats null $from_encoding as default;
 * VM TypeError despite stub allowing ?array|string|null.
 * php-src: ext/mbstring/mbstring.c php_mb_convert_encoding
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
    var_export(mb_convert_encoding('a', 'UTF-8', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
