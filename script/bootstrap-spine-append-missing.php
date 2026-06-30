#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Append missing bootstrap-inventory paths to compiler_lib_spine_smoke/main.php.
 *
 * Usage: php script/bootstrap-spine-append-missing.php [--limit=N] [--dry-run]
 */

$root = dirname(__DIR__);
require $root.'/script/bootstrap-spine-count.php';
require_once $root.'/script/bootstrap-spine-deferred-lib.php';

$limit = 50;
$dryRun = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    } elseif ('--dry-run' === $arg) {
        $dryRun = true;
    }
}

$spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$missingList = sys_get_temp_dir().'/selfhost-spine-coverage-missing.txt';
if (!is_readable($missingList)) {
    passthru('php '.escapeshellarg($root.'/script/check-selfhost-spine-coverage-sync.php').' 2>/dev/null', $exitCode);
}
if (!is_readable($missingList)) {
    fwrite(STDERR, "missing list not found: run check-selfhost-spine-coverage-sync.php first\n");
    exit(1);
}
$missing = array_values(array_filter(array_map('trim', file($missingList, FILE_IGNORE_NEW_LINES) ?: [])));
$batch = array_slice($missing, 0, $limit);
if ([] === $batch) {
    fwrite(STDOUT, "bootstrap-spine-append-missing: nothing to add\n");
    exit(0);
}

$lines = [];
foreach ($batch as $rel) {
    $lines[] = "require_once __DIR__.'/../../../{$rel}';";
}
$block = implode("\n", $lines)."\n";
$marker = "// VM -r smoke: bootstrap-selfhost-lib-spine-vm-smoke.sh (#1846).";
$existing = (string) file_get_contents($spineMain);
if (!str_contains($existing, $marker)) {
    fwrite(STDERR, "marker not found in {$spineMain}\n");
    exit(1);
}
if ($dryRun) {
    fwrite(STDOUT, $block);
    exit(0);
}
$pos = strrpos($existing, $marker);
if (false === $pos) {
    fwrite(STDERR, "marker not found in {$spineMain}\n");
    exit(1);
}
file_put_contents($spineMain, substr($existing, 0, $pos).$block.$marker.substr($existing, $pos + strlen($marker)));
$repair = $root.'/script/bootstrap-spine-repair-main.php';
if (is_readable($repair)) {
    passthru('php '.escapeshellarg($repair), $repairExit);
    if (0 !== $repairExit) {
        fwrite(STDERR, "bootstrap-spine-append-missing: repair failed (exit {$repairExit})\n");
        exit($repairExit);
    }
}
fwrite(STDOUT, 'bootstrap-spine-append-missing: added '.count($batch)." files (".count($missing)." still missing)\n");
