#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M3 compile-driver allowlist/denylist against lib/JIT.php drift (#1905, #1768).
 *
 * Usage:
 *   php script/check-m3-allowlist-snapshot.php
 */

require __DIR__.'/bootstrap-m3-allowlist.php';

$root = dirname(__DIR__);
$jitPath = $root.'/lib/JIT.php';
$snapshotPath = $root.'/script/m3-allowlist-snapshot.txt';

$fromJit = bootstrap_m3_allowlist_from_jit($jitPath);
$fromSnapshot = bootstrap_m3_allowlist_read_snapshot($snapshotPath);

$errors = [];

if (!is_readable($jitPath)) {
    $errors[] = 'missing lib/JIT.php';
}
if (!is_readable($snapshotPath)) {
    $errors[] = 'missing script/m3-allowlist-snapshot.txt — run: php script/bootstrap-m3-allowlist-snapshot.php --write';
}

if ([] === $errors) {
    foreach (['allow', 'deny'] as $section) {
        $jitList = $fromJit[$section];
        $snapList = $fromSnapshot[$section];
        $missing = array_values(array_diff($jitList, $snapList));
        $extra = array_values(array_diff($snapList, $jitList));
        if ([] !== $missing) {
            $errors[] = "{$section}: snapshot missing ".implode(', ', $missing);
        }
        if ([] !== $extra) {
            $errors[] = "{$section}: snapshot stale ".implode(', ', $extra);
        }
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-m3-allowlist-snapshot: {$err}\n");
    }
    fwrite(STDERR, "check-m3-allowlist-snapshot: FAILED — run: php script/bootstrap-m3-allowlist-snapshot.php --write\n");
    exit(1);
}

fwrite(STDOUT, 'check-m3-allowlist-snapshot: OK (allow '.count($fromJit['allow']).', deny '.count($fromJit['deny']).")\n");
exit(0);
