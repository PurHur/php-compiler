#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard committed gen-0 argv driver bytes against manifest.json (issue #8713).
 *
 * Usage:
 *   php script/check-bootstrap-gen0-manifest-sync.php
 */

require __DIR__.'/bootstrap-gen0-manifest-lib.php';

$root = dirname(__DIR__);
$errors = bootstrap_gen0_manifest_sync_errors($root);

if ([] === $errors) {
    $manifest = bootstrap_gen0_manifest_read($root);
    $driverBytes = (int) ($manifest['size_bytes_driver'] ?? 0);
    fwrite(STDOUT, "check-bootstrap-gen0-manifest-sync: OK (gen-0 argv driver {$driverBytes} bytes, manifest match)\n");
    exit(0);
}

fwrite(STDERR, 'check-bootstrap-gen0-manifest-sync: FAILED — '.count($errors)." mismatch(es):\n");
foreach ($errors as $error) {
    fwrite(STDERR, "  - {$error}\n");
}
fwrite(STDERR, "Update prelinked/bootstrap-gen0/manifest.json after intentional driver refresh (#8713).\n");
exit(1);
