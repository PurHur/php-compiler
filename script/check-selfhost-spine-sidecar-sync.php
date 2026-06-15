#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard prelinked gen-0 sidecar stamp against M2 spine entry drift (issue #8703).
 *
 * Canonical hash: SHA-1 of test/selfhost/compiler_lib_spine_smoke/main.php
 * (same as bootstrap_compiler_lib_spine_entry_sha in bootstrap-gen0-install-prelinked-driver.sh).
 *
 * Usage:
 *   php script/check-selfhost-spine-sidecar-sync.php
 *   BOOTSTRAP_ALLOW_STALE_SIDECAR=1 php script/check-selfhost-spine-sidecar-sync.php
 */

$root = dirname(__DIR__);
$spineEntry = getenv('PHP_COMPILER_SPINE_SIDECAR_TEST_SPINE')
    ?: $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
$stampPath = getenv('PHP_COMPILER_SPINE_SIDECAR_TEST_STAMP')
    ?: $root.'/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha';

if (!is_readable($spineEntry)) {
    fwrite(STDERR, "check-selfhost-spine-sidecar-sync: missing spine entry {$spineEntry}\n");
    exit(1);
}

$wantSha = hash_file('sha1', $spineEntry);
if (!is_string($wantSha) || '' === $wantSha) {
    fwrite(STDERR, "check-selfhost-spine-sidecar-sync: failed to hash {$spineEntry}\n");
    exit(1);
}

if (!is_readable($stampPath)) {
    fwrite(STDERR, "check-selfhost-spine-sidecar-sync: missing stamp {$stampPath}\n");
    fwrite(STDERR, "Next: make bootstrap-gen0-refresh-sidecar (issues #8703, #8704)\n");
    exit(1);
}

$haveSha = trim((string) file_get_contents($stampPath));
if ('' === $haveSha) {
    fwrite(STDERR, "check-selfhost-spine-sidecar-sync: empty stamp {$stampPath}\n");
    exit(1);
}

if ($wantSha === $haveSha) {
    fwrite(STDOUT, "check-selfhost-spine-sidecar-sync: OK (stamp matches spine entry SHA-1 {$wantSha})\n");
    exit(0);
}

if ('1' === getenv('BOOTSTRAP_ALLOW_STALE_SIDECAR')) {
    fwrite(STDOUT, "check-selfhost-spine-sidecar-sync: WAIVED (BOOTSTRAP_ALLOW_STALE_SIDECAR=1; stamp {$haveSha} ≠ spine {$wantSha})\n");
    exit(0);
}

fwrite(STDERR, "check-selfhost-spine-sidecar-sync: FAILED — stamp {$haveSha} ≠ spine entry SHA-1 {$wantSha}\n");
fwrite(STDERR, "  Spine SSOT: test/selfhost/compiler_lib_spine_smoke/main.php\n");
fwrite(STDERR, "  Stamp:      prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha\n");
fwrite(STDERR, "Next:\n");
fwrite(STDERR, "  make bootstrap-gen0-refresh-sidecar\n");
fwrite(STDERR, "  # or stamp-only batch PRs: BOOTSTRAP_ALLOW_STALE_SIDECAR=1 (intentional blob refresh follow-up)\n");
exit(1);
