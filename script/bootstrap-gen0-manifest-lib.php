<?php

declare(strict_types=1);

/**
 * Gen-0 prelinked argv driver manifest helpers (issues #8713, #3053, #3046).
 *
 * @return array<string, mixed>|null
 */
function bootstrap_gen0_manifest_read(string $root): ?array
{
    $path = bootstrap_gen0_manifest_path($root);
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

function bootstrap_gen0_manifest_path(string $root): string
{
    return rtrim($root, '/').'/prelinked/bootstrap-gen0/manifest.json';
}

/**
 * @return list<string>
 */
function bootstrap_gen0_manifest_sync_errors(string $root): array
{
    $manifest = bootstrap_gen0_manifest_read($root);
    if (null === $manifest) {
        return ['missing or invalid '.bootstrap_gen0_manifest_path($root)];
    }

    $errors = [];
    $checks = [
        'driver' => [
            'rel' => (string) ($manifest['driver'] ?? 'prelinked/bootstrap-gen0/bin-compile-aot'),
            'size' => 'size_bytes_driver',
            'sha' => 'sha256_driver',
        ],
        'compiler_minimal_sidecar' => [
            'rel' => (string) ($manifest['compiler_minimal_sidecar'] ?? 'prelinked/bootstrap-gen0/compiler_minimal_aot_blob'),
            'size' => 'size_bytes_compiler_minimal_sidecar',
            'sha' => 'sha256_compiler_minimal_sidecar',
        ],
        'compiler_lib_sidecar' => [
            'rel' => (string) ($manifest['compiler_lib_sidecar'] ?? 'prelinked/bootstrap-gen0/compiler_lib_aot_blob'),
            'size' => 'size_bytes_compiler_lib_sidecar',
            'sha' => 'sha256_compiler_lib_sidecar',
        ],
    ];

    foreach ($checks as $label => $spec) {
        $rel = $spec['rel'];
        $abs = rtrim($root, '/').'/'.ltrim($rel, '/');
        if (!is_file($abs) || !is_readable($abs)) {
            $errors[] = "{$label}: missing {$rel}";
            continue;
        }
        $bytes = filesize($abs);
        $wantSize = (int) ($manifest[$spec['size']] ?? 0);
        if ($wantSize > 0 && false !== $bytes && (int) $bytes !== $wantSize) {
            $errors[] = "{$label}: size mismatch for {$rel} (manifest {$wantSize}, on-disk ".(int) $bytes.')';
        }
        $wantSha = strtolower((string) ($manifest[$spec['sha']] ?? ''));
        if ('' !== $wantSha) {
            $haveSha = hash_file('sha256', $abs);
            if (!is_string($haveSha) || strtolower($haveSha) !== $wantSha) {
                $errors[] = "{$label}: sha256 mismatch for {$rel} (manifest {$wantSha}, on-disk {$haveSha})";
            }
        }
    }

    $driverRel = (string) ($manifest['driver'] ?? 'prelinked/bootstrap-gen0/bin-compile-aot');
    $m3Rel = 'prelinked/bootstrap-gen0/.m3_bin_compile_aot_blob';
    $driverAbs = rtrim($root, '/').'/'.ltrim($driverRel, '/');
    $m3Abs = rtrim($root, '/').'/'.$m3Rel;
    if (is_file($driverAbs) && is_file($m3Abs)) {
        $driverSha = hash_file('sha256', $driverAbs);
        $m3Sha = hash_file('sha256', $m3Abs);
        if (is_string($driverSha) && is_string($m3Sha) && $driverSha !== $m3Sha) {
            $errors[] = ".m3_bin_compile_aot_blob sha256 must match {$driverRel}";
        }
    }

    return $errors;
}

function bootstrap_gen0_manifest_driver_min_bytes(string $root): int
{
    $manifest = bootstrap_gen0_manifest_read($root);
    if (null === $manifest) {
        return 0;
    }

    return max(0, (int) ($manifest['size_bytes_driver'] ?? 0));
}
