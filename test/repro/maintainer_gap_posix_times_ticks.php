<?php

declare(strict_types=1);

// Repro #13524 — posix_times()['ticks'] must match Zend libc order of magnitude.
if (!\function_exists('posix_times')) {
    echo "skip: posix_times unavailable\n";
    exit(0);
}

$t = \posix_times();
if (!\is_array($t)) {
    echo "fail: posix_times() returned ", \var_export($t, true), "\n";
    exit(1);
}

$ticks = (int) ($t['ticks'] ?? -1);
if ($ticks <= 0) {
    echo "fail: ticks={$ticks}\n";
    exit(1);
}

$refPath = \sys_get_temp_dir().'/phpc_posix_times_ref_ticks.txt';
if (\in_array('--write-ref', $argv ?? [], true)) {
    \file_put_contents($refPath, (string) $ticks);
    echo "ok\n";
    exit(0);
}

if (\is_file($refPath)) {
    $ref = (int) \trim((string) \file_get_contents($refPath));
    if ($ref > 0) {
        $ratio = \max($ticks, $ref) / \max(1, \min($ticks, $ref));
        if ($ratio > 10.0) {
            echo "fail: ticks={$ticks} ref={$ref} ratio={$ratio}\n";
            exit(1);
        }
        echo "ok\n";
        exit(0);
    }
}

// Standalone: libc times() on Linux is typically 1e8+ at steady state.
if ($ticks < 1_000_000) {
    echo "fail: ticks={$ticks} too small (boot-time approximation bug)\n";
    exit(1);
}

echo "ok\n";
