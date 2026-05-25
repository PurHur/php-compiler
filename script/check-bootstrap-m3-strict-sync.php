#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard M3 compile-smoke strict emit_path docs against probe script drift (issue #2176).
 *
 * Usage:
 *   php script/check-bootstrap-m3-strict-sync.php
 *
 * Opt-in in ci-fast: BOOTSTRAP_M3_STRICT_SYNC_GATE=1 ./script/ci-fast.sh
 */

require_once __DIR__.'/bootstrap-m3-strict-sync-lib.php';

$root = dirname(__DIR__);
$probe = $root.'/script/bootstrap-selfhost-compile-smoke-probe.sh';
$bootstrapDoc = $root.'/docs/bootstrap-selfhost.md';
$matrixDoc = $root.'/docs/local-ci-matrix.md';
$statusDoc = $root.'/docs/pages/development-status.md';

$errors = [];

if (!is_readable($probe)) {
    $errors[] = 'missing script: bootstrap-selfhost-compile-smoke-probe.sh';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-bootstrap-m3-strict-sync: {$err}\n");
    }
    exit(1);
}

$probeSource = (string) file_get_contents($probe);
$profile = bootstrap_m3_compile_smoke_script_profile($probeSource);

if (!$profile['zend_fallback'] && !$profile['native_success']) {
    $errors[] = 'bootstrap-selfhost-compile-smoke-probe.sh: expected Zend fallback or native OK emit_path lines';
}

if (is_readable($bootstrapDoc)) {
    bootstrap_m3_strict_validate_doc('docs/bootstrap-selfhost.md', (string) file_get_contents($bootstrapDoc), $profile, $errors);
} else {
    $errors[] = 'missing doc: docs/bootstrap-selfhost.md';
}

if (is_readable($matrixDoc)) {
    bootstrap_m3_strict_validate_local_ci_matrix((string) file_get_contents($matrixDoc), $errors);
} else {
    $errors[] = 'missing doc: docs/local-ci-matrix.md';
}

if (is_readable($statusDoc)) {
    bootstrap_m3_strict_validate_development_status((string) file_get_contents($statusDoc), $errors);
} else {
    $errors[] = 'missing doc: docs/pages/development-status.md';
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-bootstrap-m3-strict-sync: {$err}\n");
    }
    fwrite(STDERR, "check-bootstrap-m3-strict-sync: FAILED — sync M3 compile-smoke strict docs with bootstrap-selfhost-compile-smoke-probe.sh (#2176).\n");
    exit(1);
}

$mode = $profile['native_success'] && !$profile['zend_fallback'] ? 'native-only' : ($profile['zend_fallback'] ? 'zend-partial' : 'unknown');
fwrite(STDOUT, "check-bootstrap-m3-strict-sync: OK (M3 compile-smoke profile {$mode}; BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=".($profile['strict_env'] ? 'yes' : 'no').")\n");
exit(0);
