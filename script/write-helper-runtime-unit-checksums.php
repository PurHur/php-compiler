#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Write prelinked/helper-runtime/<arch>/UNITS_SHA256SUMS from on-disk unit.o/unit.bc (#36399).
 *
 * Usage:
 *   php script/write-helper-runtime-unit-checksums.php              # current TARGET
 *   php script/write-helper-runtime-unit-checksums.php --all-arches
 */

use PHPCompiler\AOT\CompileTarget;
use PHPCompiler\AOT\HelperRuntimeCache;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/helper-runtime-unit-checksums-lib.php';

$allArches = in_array('--all-arches', $argv, true);
$archIds = $allArches
    ? [CompileTarget::ID_X86_64_LINUX, CompileTarget::ID_AARCH64_LINUX]
    : [HelperRuntimeCache::archKey()];

$savedTarget = \PHPCompiler\Config::getenv(CompileTarget::ENV);
$wrote = 0;

foreach ($archIds as $archId) {
    putenv(CompileTarget::ENV.'='.$archId);
    $_ENV[CompileTarget::ENV] = $archId;
    $_SERVER[CompileTarget::ENV] = $archId;
    CompileTarget::resetCache();

    $archDir = dirname(HelperRuntimeCache::prelinkedUnitsDir());
    if (!is_dir($archDir.'/units')) {
        fwrite(STDOUT, "write-helper-runtime-unit-checksums: {$archId} — no units dir, skip\n");
        continue;
    }
    try {
        $n = helper_runtime_write_units_sha256sums($archDir);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'write-helper-runtime-unit-checksums: '.$e->getMessage()."\n");
        exit(1);
    }
    $wrote += $n;
    fwrite(STDOUT, "write-helper-runtime-unit-checksums: {$archId} — {$n} file(s) → ".helper_runtime_units_sha256sums_path($archDir)."\n");
}

if (false === $savedTarget || null === $savedTarget || '' === $savedTarget) {
    putenv(CompileTarget::ENV);
    unset($_ENV[CompileTarget::ENV], $_SERVER[CompileTarget::ENV]);
} else {
    putenv(CompileTarget::ENV.'='.$savedTarget);
    $_ENV[CompileTarget::ENV] = $savedTarget;
    $_SERVER[CompileTarget::ENV] = $savedTarget;
}
CompileTarget::resetCache();

exit($wrote > 0 ? 0 : 1);
