<?php

declare(strict_types=1);

/**
 * Gen-0 prelinked argv driver manifest helpers (issues #8713, #3053, #3046, #21905).
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
 * Live lowering-source fingerprint for committed gen-0 provenance (#21905).
 *
 * Requires script/bootstrap-lowering-source-fingerprint.php (safe to include).
 */
function bootstrap_gen0_manifest_live_lowering_fingerprint(string $root): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }
    $script = rtrim($root, '/').'/script/bootstrap-lowering-source-fingerprint.php';
    if (!is_readable($script)) {
        throw new \RuntimeException('missing '.$script);
    }
    require_once $script;
    $cached = bootstrap_lowering_source_fingerprint($root);

    return $cached;
}

/**
 * Hard errors when manifest carries a lowering fingerprint that disagrees with live source.
 *
 * Missing fingerprint is a warning only (see bootstrap_gen0_manifest_sync_warnings) until
 * the next verified-fresh refresh stamps it (#21905; rebuild blocked on #21886).
 *
 * @return list<string>
 */
function bootstrap_gen0_manifest_lowering_fingerprint_errors(string $root, ?array $manifest = null): array
{
    if (null === $manifest) {
        $manifest = bootstrap_gen0_manifest_read($root);
    }
    if (null === $manifest) {
        return [];
    }
    $have = strtolower(trim((string) ($manifest['lowering_source_fingerprint'] ?? '')));
    if ('' === $have) {
        return [];
    }
    if ('1' === getenv('BOOTSTRAP_ALLOW_STALE_COMPILED_DRIVER')
        || '1' === getenv('BOOTSTRAP_ALLOW_STALE_SIDECAR')) {
        return [];
    }
    try {
        $want = strtolower(bootstrap_gen0_manifest_live_lowering_fingerprint($root));
    } catch (\Throwable $e) {
        return ['lowering_source_fingerprint: cannot compute live fingerprint ('.$e->getMessage().')'];
    }
    if ($have !== $want) {
        return [
            "lowering_source_fingerprint mismatch (manifest {$have}, live {$want}) — refresh via script/bootstrap-refresh-gen0-sidecar.sh from a verified-fresh build/ (#21905)",
        ];
    }

    return [];
}

/**
 * Soft provenance gaps (missing stamp) — loud warn, not a hard sync failure (#21905).
 *
 * @return list<string>
 */
function bootstrap_gen0_manifest_sync_warnings(string $root, ?array $manifest = null): array
{
    if (null === $manifest) {
        $manifest = bootstrap_gen0_manifest_read($root);
    }
    if (null === $manifest) {
        return [];
    }
    $warnings = [];
    $have = trim((string) ($manifest['lowering_source_fingerprint'] ?? ''));
    if ('' === $have) {
        $warnings[] = 'manifest missing lowering_source_fingerprint — committed gen-0 blobs have no lowering provenance until the next verified-fresh refresh (#21905)';
    }
    $stampRel = 'prelinked/bootstrap-gen0/.bootstrap_lowering_source.sha';
    $stampAbs = rtrim($root, '/').'/'.$stampRel;
    if ('' !== $have && is_readable($stampAbs)) {
        $stamp = strtolower(trim((string) file_get_contents($stampAbs)));
        if ('' !== $stamp && $stamp !== strtolower($have)) {
            $warnings[] = ".bootstrap_lowering_source.sha ({$stamp}) disagrees with manifest lowering_source_fingerprint ({$have})";
        }
    }

    return $warnings;
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

    foreach (bootstrap_gen0_manifest_lowering_fingerprint_errors($root, $manifest) as $fpError) {
        $errors[] = $fpError;
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
 * Does **not** set or refresh `lowering_source_fingerprint` — that would stamp today's
 * source onto possibly-ancient blobs (false provenance). Use
 * {@see bootstrap_gen0_manifest_stamp_lowering_fingerprint()} only from a verified-fresh
 * copy path in script/bootstrap-refresh-gen0-sidecar.sh (#21905).
 *
 * @return array<string, mixed>
 */
function bootstrap_gen0_manifest_refresh_from_disk(string $root): array
{
    $manifest = bootstrap_gen0_manifest_read($root);
    if (null === $manifest) {
        throw new \RuntimeException('missing or invalid '.bootstrap_gen0_manifest_path($root));
    }

    // Preserve any existing provenance claim; never invent one from live source here.
    $preservedFingerprint = null;
    if (array_key_exists('lowering_source_fingerprint', $manifest)) {
        $preservedFingerprint = $manifest['lowering_source_fingerprint'];
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
    if (null === $preservedFingerprint) {
        unset($manifest['lowering_source_fingerprint']);
    } else {
        $manifest['lowering_source_fingerprint'] = $preservedFingerprint;
    }

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if (false === file_put_contents(bootstrap_gen0_manifest_path($root), $encoded)) {
        throw new \RuntimeException('failed writing '.bootstrap_gen0_manifest_path($root));
    }

    return $manifest;
}

/**
 * Record lowering provenance after a verified-fresh copy into prelinked/bootstrap-gen0/ (#21905).
 *
 * When stamping the live fingerprint (null $fingerprint), requires build/.bootstrap_lowering_source.sha
 * to already match — written by script/bootstrap-refresh-gen0-sidecar.sh after spine link.
 * That rejects restamp-only edits that invent provenance without a verified-fresh build (#22642).
 *
 * @return array<string, mixed>
 */
function bootstrap_gen0_manifest_stamp_lowering_fingerprint(string $root, ?string $fingerprint = null): array
{
    $manifest = bootstrap_gen0_manifest_read($root);
    if (null === $manifest) {
        throw new \RuntimeException('missing or invalid '.bootstrap_gen0_manifest_path($root));
    }
    $fp = $fingerprint;
    $stampingLive = null === $fp || '' === $fp;
    if ($stampingLive) {
        $fp = bootstrap_gen0_manifest_live_lowering_fingerprint($root);
    }
    $fp = strtolower(trim($fp));
    if (!preg_match('/^[a-f0-9]{64}$/', $fp)) {
        throw new \RuntimeException('invalid lowering_source_fingerprint (want 64 hex chars)');
    }
    if ($stampingLive) {
        $buildStamp = rtrim($root, '/').'/build/.bootstrap_lowering_source.sha';
        $have = is_readable($buildStamp) ? strtolower(trim((string) file_get_contents($buildStamp))) : '';
        if ($have !== $fp) {
            throw new \RuntimeException(
                'refusing to stamp live lowering_source_fingerprint without a matching build/'.
                '.bootstrap_lowering_source.sha (run script/bootstrap-refresh-gen0-sidecar.sh,'.
                ' not a restamp-only edit — #22642/#21905)'
            );
        }
    }
    $manifest['lowering_source_fingerprint'] = $fp;
    $manifest['generated_at'] = gmdate('c');

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if (false === file_put_contents(bootstrap_gen0_manifest_path($root), $encoded)) {
        throw new \RuntimeException('failed writing '.bootstrap_gen0_manifest_path($root));
    }

    $stampAbs = rtrim($root, '/').'/prelinked/bootstrap-gen0/.bootstrap_lowering_source.sha';
    if (false === file_put_contents($stampAbs, $fp)) {
        throw new \RuntimeException('failed writing '.$stampAbs);
    }

    return $manifest;
}
