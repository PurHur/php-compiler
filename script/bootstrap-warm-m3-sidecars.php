#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pre-build independent M3 link-time sidecars before bootstrap emit/link.
 *
 * Usage:
 *   php script/bootstrap-warm-m3-sidecars.php
 *   php script/bootstrap-warm-m3-sidecars.php --force
 *   php script/bootstrap-warm-m3-sidecars.php --heavy   # include full compiler_lib spine (8GB)
 *
 * Parallel fan-out: PHP_COMPILER_COMPILE_JOBS=N (default 1). RAM scales with jobs × memory_limit.
 */

$root = dirname(__DIR__);
require $root.'/script/bootstrap-m3-sidecar-warm-lib.php';

$force = in_array('--force', $argv, true);
$includeHeavy = in_array('--heavy', $argv, true);
$list = in_array('--list', $argv, true);

$jobs = bootstrapM3SidecarWarmJobDefinitions($root);
if (!$includeHeavy) {
    $jobs = array_values(array_filter(
        $jobs,
        static fn (array $job): bool => empty($job['sequential'])
    ));
}

if ($list) {
    foreach ($jobs as $job) {
        fwrite(STDOUT, $job['id']."\t".$job['source']."\t".$job['sidecar']."\n");
    }
    exit(0);
}

if (!is_file($root.'/bin/compile.php')) {
    fwrite(STDERR, "bootstrap-warm-m3-sidecars: missing {$root}/bin/compile.php\n");
    exit(1);
}

$llvm = getenv('PHP_COMPILER_LLVM_PATH') ?: '';
if ('' === $llvm || !is_file($llvm.'/libLLVM-9.so.1')) {
    fwrite(STDERR, "bootstrap-warm-m3-sidecars: LLVM 9 not found (set PHP_COMPILER_LLVM_PATH)\n");
    exit(2);
}

mkdir($root.'/build', 0775, true);

$failures = bootstrapM3SidecarWarmRun($root, $jobs, $force);
if ($failures > 0) {
    fwrite(STDERR, "bootstrap-warm-m3-sidecars: {$failures} job(s) failed\n");
    exit(1);
}

fwrite(STDOUT, "bootstrap-warm-m3-sidecars: all jobs OK\n");
exit(0);
