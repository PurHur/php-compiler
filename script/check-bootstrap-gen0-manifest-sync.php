#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard committed gen-0 argv driver bytes against manifest.json (issue #8713, #21905).
 *
 * Usage:
 *   php script/check-bootstrap-gen0-manifest-sync.php
 */

require __DIR__.'/bootstrap-gen0-manifest-lib.php';

$root = dirname(__DIR__);
$manifestBefore = bootstrap_gen0_manifest_read($root);
$errors = bootstrap_gen0_manifest_sync_errors($root);
$manifestAfter = bootstrap_gen0_manifest_read($root);
if (is_array($manifestBefore) && is_array($manifestAfter)
    && 'verified-fresh' === trim((string) ($manifestBefore['provenance'] ?? ''))
    && 'unverified-restamp' === trim((string) ($manifestAfter['provenance'] ?? ''))) {
    fwrite(STDERR, "check-bootstrap-gen0-manifest-sync: downgraded provenance verified-fresh → unverified-restamp (lowering drift, blobs unchanged — rebuild via script/bootstrap-refresh-gen0-sidecar.sh; #10533)\n");
}
$warnings = bootstrap_gen0_manifest_sync_warnings($root);

foreach ($warnings as $warning) {
    fwrite(STDERR, "check-bootstrap-gen0-manifest-sync: WARNING — {$warning}\n");
}

if ([] === $errors) {
    $manifest = bootstrap_gen0_manifest_read($root);
    $driverBytes = (int) ($manifest['size_bytes_driver'] ?? 0);
    $fp = trim((string) ($manifest['lowering_source_fingerprint'] ?? ''));
    $fpNote = '' !== $fp ? ', lowering_source_fingerprint='.substr($fp, 0, 12).'…' : ', lowering_source_fingerprint=<missing>';
    fwrite(STDOUT, "check-bootstrap-gen0-manifest-sync: OK (gen-0 argv driver {$driverBytes} bytes, manifest match{$fpNote})\n");
    exit(0);
}

fwrite(STDERR, 'check-bootstrap-gen0-manifest-sync: FAILED — '.count($errors)." mismatch(es):\n");
foreach ($errors as $error) {
    fwrite(STDERR, "  - {$error}\n");
}
fwrite(STDERR, "Update prelinked/bootstrap-gen0/manifest.json after intentional driver refresh (#8713, #21905).\n");
exit(1);
