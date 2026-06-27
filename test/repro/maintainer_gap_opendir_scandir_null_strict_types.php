<?php

declare(strict_types=1);

// Repro for #12665 — opendir/scandir(null) under strict_types must TypeError (php-src dir.c).
$checks = [
    ['opendir', [null], 'opendir(): Argument #1 ($directory) must be of type string, null given'],
    ['scandir', [null], 'scandir(): Argument #1 ($directory) must be of type string, null given'],
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
