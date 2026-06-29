<?php

declare(strict_types=1);

// Repro #13522 — VM getrusage ru_utime.tv_usec must track Zend libc within ~3× (CLK_TCK SSOT).
$usage = \getrusage();
if (!\is_array($usage)) {
    echo "fail: getrusage() returned ", \var_export($usage, true), "\n";
    exit(1);
}

$usec = (int) ($usage['ru_utime.tv_usec'] ?? -1);
$sec = (int) ($usage['ru_utime.tv_sec'] ?? -1);
if ($usec < 0 || $sec < 0) {
    echo "fail: missing ru_utime fields\n";
    exit(1);
}

$refPath = \sys_get_temp_dir().'/phpc_getrusage_ref_usec.txt';
if (\in_array('--write-ref', $argv ?? [], true)) {
    \file_put_contents($refPath, (string) $usec);
    echo "ok\n";
    exit(0);
}

if (\is_file($refPath)) {
    $ref = (int) \trim((string) \file_get_contents($refPath));
    if ($ref > 0) {
        $ratio = \max($usec, $ref) / \max(1, \min($usec, $ref));
        if ($ratio > 3.0) {
            echo "fail: ru_utime.tv_usec={$usec} ref={$ref} ratio={$ratio}\n";
            exit(1);
        }
        echo "ok\n";
        exit(0);
    }
}

// Standalone sanity: wrong CLK_TCK=100 default used to yield usec >= 1e6 at tv_sec=0.
if (0 === $sec && $usec >= 1_000_000) {
    echo "fail: ru_utime.tv_usec={$usec} with tv_sec=0 (CLK_TCK mismatch)\n";
    exit(1);
}

echo "ok\n";
