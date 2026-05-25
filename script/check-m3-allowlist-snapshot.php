#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M3 compile-driver allow/deny lists against committed snapshot (issues #1768, #1905).
 *
 * Canonical: lib/JIT.php via script/bootstrap-m3-allowlist-snapshot.php
 *
 * Usage:
 *   php script/check-m3-allowlist-snapshot.php
 */

require __DIR__.'/bootstrap-m3-allowlist-snapshot.php';

$root = dirname(__DIR__);
$snapshotPath = $root.'/script/m3-allowlist-snapshot.txt';

$expected = m3_allowlist_snapshot_lines($root);
if ([] === $expected) {
    fwrite(STDERR, "check-m3-allowlist-snapshot: could not parse lib/JIT.php M3 allowlists\n");
    exit(1);
}

if (!is_readable($snapshotPath)) {
    fwrite(STDERR, "check-m3-allowlist-snapshot: missing {$snapshotPath}\n");
    fwrite(STDERR, "check-m3-allowlist-snapshot: run: php script/bootstrap-m3-allowlist-snapshot.php --write\n");
    exit(1);
}

$committed = array_values(array_filter(
    array_map('trim', file($snapshotPath, FILE_IGNORE_NEW_LINES) ?: []),
    static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#')
));
sort($committed, SORT_STRING);
$sortedExpected = $expected;
sort($sortedExpected, SORT_STRING);

if ($committed !== $sortedExpected) {
    $onlyCommitted = array_values(array_diff($committed, $sortedExpected));
    $onlyExpected = array_values(array_diff($sortedExpected, $committed));
    fwrite(STDERR, "check-m3-allowlist-snapshot: FAILED — lib/JIT.php drift from script/m3-allowlist-snapshot.txt (issues #1768, #1905).\n");
    if ([] !== $onlyExpected) {
        fwrite(STDERR, "  missing from snapshot (+".count($onlyExpected)."): ".implode(', ', array_slice($onlyExpected, 0, 8)).(count($onlyExpected) > 8 ? '…' : '')."\n");
    }
    if ([] !== $onlyCommitted) {
        fwrite(STDERR, "  stale in snapshot (-".count($onlyCommitted)."): ".implode(', ', array_slice($onlyCommitted, 0, 8)).(count($onlyCommitted) > 8 ? '…' : '')."\n");
    }
    fwrite(STDERR, "check-m3-allowlist-snapshot: regenerate: php script/bootstrap-m3-allowlist-snapshot.php --write\n");
    exit(1);
}

$allow = 0;
$deny = 0;
foreach ($sortedExpected as $line) {
    if (str_starts_with($line, 'allow:')) {
        ++$allow;
    } elseif (str_starts_with($line, 'deny:')) {
        ++$deny;
    }
}

fwrite(STDOUT, "check-m3-allowlist-snapshot: OK (allow={$allow} deny={$deny} from lib/JIT.php)\n");
exit(0);
