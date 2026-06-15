#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Rewrite prelinked/bootstrap-gen0/manifest.json from on-disk blob sha256/size (#8704).
 *
 * Usage:
 *   php script/bootstrap-gen0-manifest-refresh.php
 */

require __DIR__.'/bootstrap-gen0-manifest-lib.php';

$root = dirname(__DIR__);

try {
    $manifest = bootstrap_gen0_manifest_refresh_from_disk($root);
} catch (\Throwable $e) {
    fwrite(STDERR, 'bootstrap-gen0-manifest-refresh: '.$e->getMessage()."\n");
    exit(1);
}

$libBytes = (int) ($manifest['size_bytes_compiler_lib_sidecar'] ?? 0);
fwrite(
    STDOUT,
    "bootstrap-gen0-manifest-refresh: OK (compiler_lib_sidecar {$libBytes} bytes, generated_at="
    .($manifest['generated_at'] ?? '?').")\n"
);
