#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Audit PHP_COMPILER_SELFHOST_AOT stub surface on the compile spine (#8720, #1520).
 *
 * Usage:
 *   php script/audit-selfhost-aot-stubs.php           # write docs + snapshot
 *   php script/audit-selfhost-aot-stubs.php --check # exit 1 on snapshot drift
 */

require __DIR__.'/selfhost-aot-stub-audit-lib.php';

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$mdPath = $root.'/docs/selfhost-aot-stub-audit.md';
$snapshotPath = $root.'/script/selfhost-aot-stub-audit-snapshot.json';

$metrics = selfhost_aot_stub_collect_metrics($root);
$payload = selfhost_aot_stub_snapshot_payload($metrics);
$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

if ($check) {
    if (!is_readable($snapshotPath)) {
        fwrite(STDERR, "audit-selfhost-aot-stubs: missing {$snapshotPath} — run without --check first\n");
        exit(1);
    }
    $current = json_decode((string) file_get_contents($snapshotPath), true);
    if (!is_array($current)) {
        fwrite(STDERR, "audit-selfhost-aot-stubs: invalid snapshot JSON\n");
        exit(1);
    }
    if ($current !== $payload) {
        fwrite(STDERR, "audit-selfhost-aot-stubs: snapshot drift — run: php script/audit-selfhost-aot-stubs.php\n");
        fwrite(STDERR, "  expected: ".json_encode($payload)."\n");
        fwrite(STDERR, "  snapshot: ".json_encode($current)."\n");
        exit(1);
    }
    fwrite(STDOUT, 'audit-selfhost-aot-stubs: OK (compiler_skip='.$payload['compiler_skip_patterns']
        .', m3_allow='.$payload['m3_allow'].', spine_stubbed='
        .($payload['spine']['entry_stub'] + $payload['spine']['compiler_stub'] + $payload['spine']['m3_deny']).")\n");
    exit(0);
}

file_put_contents($mdPath, selfhost_aot_stub_render_markdown($metrics));
file_put_contents($snapshotPath, $encoded);

$stubbed = $payload['spine']['entry_stub'] + $payload['spine']['compiler_stub'] + $payload['spine']['m3_deny'];
fwrite(
    STDOUT,
    'Wrote '.$mdPath.' and '.$snapshotPath.' (compiler_skip='.$payload['compiler_skip_patterns']
    .', m3_allow='.$payload['m3_allow'].', spine_stubbed='.$stubbed.")\n"
);
