<?php
/**
 * session_regenerate_id(null) without active session — soft-null DEP + E_WARNING (#31444).
 * php-src: ext/session/session.c PHP_FUNCTION(session_regenerate_id)
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

$r = session_regenerate_id(null);
var_export($r);
echo "\n";
