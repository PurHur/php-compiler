<?php

/**
 * Repro #29721 — http_build_query(..., null) $numeric_prefix soft-null DEP.
 * php-src: ext/standard/http.c PHP_FUNCTION(http_build_query)
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

var_export(http_build_query(['a' => 1], null));
echo "\n";
var_export(http_build_query(['a' => 1]));
echo "\n";
var_export(http_build_query(['a' => 1], ''));
echo "\n";
