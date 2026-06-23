<?php

// Maintainer gap / issues #10442, #10441, #10547 — missing path must E_WARNING + false.
$path = '/no/such/phpc-fs-missing-warnings';
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$checks = [
    'filemtime' => static fn () => filemtime($path),
    'filesize' => static fn () => filesize($path),
    'chmod' => static fn () => chmod($path, 0644),
    'unlink' => static fn () => unlink($path),
    'touch' => static fn () => touch($path),
    'file_get_contents' => static fn () => file_get_contents($path),
    'fopen' => static fn () => fopen($path, 'r'),
    'copy' => static fn () => copy($path, '/tmp/x'),
    'rename' => static fn () => rename($path, '/tmp/y'),
];
foreach ($checks as $fn => $cb) {
    $warnings = [];
    $r = $cb();
    echo $fn, ' ', count($warnings), ' ', var_export($r, true), "\n";
}
