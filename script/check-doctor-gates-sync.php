#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard phpc doctor --gates / local-ci-matrix against ci-defaults.env drift (#2380).
 *
 * Usage:
 *   php script/check-doctor-gates-sync.php
 */

$root = dirname(__DIR__);
require __DIR__.'/doctor-gates-sync-lib.php';

try {
    $result = doctor_gates_sync_run($root);
} catch (Throwable $e) {
    fwrite(STDERR, "check-doctor-gates-sync: {$e->getMessage()}\n");
    exit(1);
}

if ([] !== $result['missing']) {
    foreach ($result['missing'] as $err) {
        fwrite(STDERR, "check-doctor-gates-sync: {$err}\n");
    }
    fwrite(STDERR, "check-doctor-gates-sync: FAILED — add rows to lib/Doctor.php and/or docs/local-ci-matrix.md, or list opt-out in docs/doctor-gates-allowlist.txt (#2380).\n");
    fwrite(STDERR, "  Regen probe: php script/check-doctor-gates-sync.php\n");
    fwrite(STDERR, "  Opt out:     DOCTOR_GATES_MATRIX_SYNC_GATE=0 ./script/ci-fast.sh\n");
    exit(1);
}

$checked = $result['checked'];
fwrite(STDOUT, "check-doctor-gates-sync: OK ({$checked} tracked gate(s) from script/ci-defaults.env).\n");
exit(0);
