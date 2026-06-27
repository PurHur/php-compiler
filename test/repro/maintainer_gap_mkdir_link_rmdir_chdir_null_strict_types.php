<?php

declare(strict_types=1);

// Repro for #12664 — mkdir/link/rmdir/chdir(null) under strict_types must TypeError (php-src filestat.c).
$checks = [
    ['mkdir', [null], 'mkdir(): Argument #1 ($directory) must be of type string, null given'],
    ['link', [null, '/tmp/x'], 'link(): Argument #1 ($target) must be of type string, null given'],
    ['rmdir', [null], 'rmdir(): Argument #1 ($directory) must be of type string, null given'],
    ['chdir', [null], 'chdir(): Argument #1 ($directory) must be of type string, null given'],
];

foreach ($checks as [$fn, $args, $expected]) {
    try {
        $fn(...$args);
        echo "fail: {$fn}(null) expected TypeError under strict_types\n";
        exit(1);
    } catch (TypeError $e) {
        if ($expected !== $e->getMessage()) {
            echo 'fail: ', $fn, '(null) got ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
