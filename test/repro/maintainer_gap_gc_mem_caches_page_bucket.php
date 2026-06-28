<?php

declare(strict_types=1);

/**
 * Repro for #12921 — gc_mem_caches() first call must match host Zend MM bucket.
 *
 * php-src: ext/standard/php_gc.c — PHP_FUNCTION(gc_mem_caches)
 */

$binary = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
$descriptorSpec = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open([$binary, '-n', '-r', 'echo gc_mem_caches();'], $descriptorSpec, $pipes);
if (!\is_resource($proc)) {
    echo "fail: could not probe host Zend gc_mem_caches()\n";
    exit(1);
}
$zendExpected = (int) trim((string) stream_get_contents($pipes[1]));
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

$vmFirst = gc_mem_caches();
$vmSecond = gc_mem_caches();

echo 'zend=', $zendExpected, "\n";
echo 'vm=', $vmFirst, "\n";

if (0 !== $vmSecond) {
    echo "fail: second call expected 0, got $vmSecond\n";
    exit(1);
}
if ($vmFirst !== $zendExpected) {
    echo "fail: gc_mem_caches first call mismatch\n";
    exit(1);
}
echo "ok\n";
