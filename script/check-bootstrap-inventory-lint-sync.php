#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard phpc lint --bootstrap-inventory report vs committed snapshot (#2210).
 *
 * Usage:
 *   php script/check-bootstrap-inventory-lint-sync.php
 */

require __DIR__.'/bootstrap-inventory-lint-sync-lib.php';

$root = dirname(__DIR__);
$snapshotPath = $root.'/docs/bootstrap-inventory-lint-snapshot.json';

$errors = [];

try {
    $live = bootstrap_inventory_lint_live_report($root);
    $snapshot = bootstrap_inventory_lint_read_snapshot($snapshotPath);
    $errors = bootstrap_inventory_lint_diff_errors($live, $snapshot);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-bootstrap-inventory-lint-sync: {$err}\n");
    }
    fwrite(STDERR, "check-bootstrap-inventory-lint-sync: FAILED — run: php script/bootstrap-inventory-lint-snapshot.php --write (#2210).\n");
    exit(1);
}

$fileCount = count($live['files'] ?? []);
fwrite(STDOUT, "check-bootstrap-inventory-lint-sync: OK ({$fileCount} file(s) with unsupported syntax in snapshot).\n");
exit(0);
