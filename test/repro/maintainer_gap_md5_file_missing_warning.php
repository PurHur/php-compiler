<?php

// Maintainer gap / issue #10625 — md5_file() missing path must warn + return false (ext/standard/md5.c).
$path = '/no/such/phpc-md5-file-missing-path';
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = md5_file($path);
restore_error_handler();
var_export($r);
echo "\n";
var_export($r === false);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Failed to open stream') ? 'warn_ok' : 'warn_bad', "\n";
}
