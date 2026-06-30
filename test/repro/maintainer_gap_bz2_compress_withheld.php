<?php

declare(strict_types=1);

if (!function_exists('bzcompress') || !function_exists('bzdecompress')) {
    echo "fail: bzcompress/bzdecompress not registered\n";
    exit(1);
}

if (!extension_loaded('bz2')) {
    echo "fail: extension_loaded('bz2') false when functions registered\n";
    exit(1);
}

$plain = str_repeat('abc', 100);
$c = bzcompress($plain, 4);
if (!is_string($c)) {
    echo "fail: bzcompress returned non-string\n";
    exit(1);
}

if (bzdecompress($c) !== $plain) {
    echo "fail: roundtrip mismatch\n";
    exit(1);
}

echo "ok\n";
