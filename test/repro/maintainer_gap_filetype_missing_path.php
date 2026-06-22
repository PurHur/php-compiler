<?php

// Maintainer gap / issues #10548, #10581 — filetype() missing path must warn + return false (ext/standard/filestat.c).
$path = '/no/such/phpc-filetype-missing-path';
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = filetype($path);
restore_error_handler();
var_export($r);
echo "\n";
var_export($r === false);
echo "\n";
var_export([] !== $warnings && str_contains($warnings[0], 'Lstat failed'));
echo "\n";
