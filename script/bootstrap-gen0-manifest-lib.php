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
    $base = bootstrap_gen0_manifest_tracked_assets();
    $checks = [
        'driver' => [
            'rel' => (string) ($manifest['driver'] ?? $base['driver']['rel']),
            'size' => $base['driver']['size'],
            'sha' => $base['driver']['sha'],
        ],
        'compiler_minimal_sidecar' => [
            'rel' => (string) ($manifest['compiler_minimal_sidecar'] ?? $base['compiler_minimal_sidecar']['rel']),
            'size' => $base['compiler_minimal_sidecar']['size'],
            'sha' => $base['compiler_minimal_sidecar']['sha'],
        ],
        'compiler_lib_sidecar' => [
            'rel' => (string) ($manifest['compiler_lib_sidecar'] ?? $base['compiler_lib_sidecar']['rel']),
            'size' => $base['compiler_lib_sidecar']['size'],
            'sha' => $base['compiler_lib_sidecar']['sha'],
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

/**
 * @return array<string, array{rel: string, size: string, sha: string}>
 */
function bootstrap_gen0_manifest_tracked_assets(): array
{
    return [
        'driver' => [
            'rel' => 'prelinked/bootstrap-gen0/bin-compile-aot',
            'size' => 'size_bytes_driver',
            'sha' => 'sha256_driver',
        ],
        'compiler_minimal_sidecar' => [
            'rel' => 'prelinked/bootstrap-gen0/compiler_minimal_aot_blob',
            'size' => 'size_bytes_compiler_minimal_sidecar',
            'sha' => 'sha256_compiler_minimal_sidecar',
        ],
        'compiler_lib_sidecar' => [
            'rel' => 'prelinked/bootstrap-gen0/compiler_lib_aot_blob',
            'size' => 'size_bytes_compiler_lib_sidecar',
            'sha' => 'sha256_compiler_lib_sidecar',
        ],
    ];
}

/**
 * Rewrite manifest size/sha fields from on-disk prelinked gen-0 blobs (#8704, #8713).
 *
 * @return array<string, mixed>
 */
function bootstrap_gen0_manifest_refresh_from_disk(string $root): array
{
    $manifest = bootstrap_gen0_manifest_read($root);
    if (null === $manifest) {
        throw new \RuntimeException('missing or invalid '.bootstrap_gen0_manifest_path($root));
    }

    $base = bootstrap_gen0_manifest_tracked_assets();
    $resolved = [
        'driver' => (string) ($manifest['driver'] ?? $base['driver']['rel']),
        'compiler_minimal_sidecar' => (string) ($manifest['compiler_minimal_sidecar'] ?? $base['compiler_minimal_sidecar']['rel']),
        'compiler_lib_sidecar' => (string) ($manifest['compiler_lib_sidecar'] ?? $base['compiler_lib_sidecar']['rel']),
    ];

    foreach ($base as $label => $defaults) {
        $rel = $resolved[$label];
        $abs = rtrim($root, '/').'/'.ltrim($rel, '/');
        if (!is_file($abs) || !is_readable($abs)) {
            throw new \RuntimeException("{$label}: missing {$rel}");
        }
        $bytes = filesize($abs);
        $sha = hash_file('sha256', $abs);
        if (false === $bytes || !is_string($sha)) {
            throw new \RuntimeException("{$label}: cannot stat {$rel}");
        }
        $manifest[$defaults['size']] = (int) $bytes;
        $manifest[$defaults['sha']] = strtolower($sha);
    }

    $manifest['generated_at'] = gmdate('c');

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if (false === file_put_contents(bootstrap_gen0_manifest_path($root), $encoded)) {
        throw new \RuntimeException('failed writing '.bootstrap_gen0_manifest_path($root));
    }

    return $manifest;
}
