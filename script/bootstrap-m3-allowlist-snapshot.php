#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerate committed M3 allowlist snapshot from lib/JIT.php (issues #1768, #1905).
 *
 * Usage:
 *   php script/bootstrap-m3-allowlist-snapshot.php          # print diff summary
 *   php script/bootstrap-m3-allowlist-snapshot.php --write  # update script/m3-allowlist-snapshot.txt
 */

require __DIR__.'/bootstrap-m3-allowlist.php';

$root = dirname(__DIR__);
$jitPath = $root.'/lib/JIT.php';
$snapshotPath = $root.'/script/m3-allowlist-snapshot.txt';
$write = in_array('--write', $argv, true);

$fromJit = bootstrap_m3_allowlist_from_jit($jitPath);
$lines = bootstrap_m3_allowlist_snapshot_lines($fromJit);
$expected = implode("\n", $lines)."\n";

if ($write) {
    file_put_contents($snapshotPath, $expected);
    fwrite(STDOUT, "bootstrap-m3-allowlist-snapshot: wrote {$snapshotPath} (allow ".count($fromJit['allow']).", deny ".count($fromJit['deny']).")\n");
    exit(0);
}

$current = is_readable($snapshotPath) ? (string) file_get_contents($snapshotPath) : '';
if ($current === $expected) {
    fwrite(STDOUT, "bootstrap-m3-allowlist-snapshot: OK (allow ".count($fromJit['allow']).", deny ".count($fromJit['deny']).")\n");
    exit(0);
}

fwrite(STDOUT, "bootstrap-m3-allowlist-snapshot: drift — run with --write (allow ".count($fromJit['allow']).", deny ".count($fromJit['deny']).")\n");
exit(1);
