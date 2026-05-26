#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard bootstrap-inventory-triage.php output vs committed snapshot (#2265).
 *
 * Usage:
 *   php script/check-bootstrap-inventory-triage-sync.php
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/bootstrap-lib.php';
require __DIR__.'/bootstrap-inventory-lint-sync-lib.php';

$snapshotPath = $root.'/docs/bootstrap-inventory-triage-top50.json';

$errors = [];

try {
    $live = bootstrap_inventory_triage_live_payload($root);
    $snapshot = bootstrap_inventory_triage_read_snapshot($snapshotPath);
    $errors = bootstrap_inventory_triage_diff_errors($live, $snapshot);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-bootstrap-inventory-triage-sync: {$err}\n");
    }
    fwrite(STDERR, "check-bootstrap-inventory-triage-sync: FAILED — run: php script/bootstrap-inventory-triage.php --json --top ".BOOTSTRAP_INVENTORY_TRIAGE_SYNC_TOP.' > docs/bootstrap-inventory-triage-top50.json (#2265).'."\n");
    exit(1);
}

$rowCount = count($live['rows']);
fwrite(STDOUT, "check-bootstrap-inventory-triage-sync: OK (top {$rowCount} CFG gap row(s), {$live['scanned']} file(s) scanned).\n");
exit(0);
