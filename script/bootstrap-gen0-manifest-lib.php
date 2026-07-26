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
    $provenance = trim((string) ($manifest['provenance'] ?? ''));
    if ('unverified-restamp' === $provenance) {
        // Soft: unverified stamps must not hard-fail 4f-m / restamp treadmill (#22642, #10533).
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

    $provenance = trim((string) ($manifest['provenance'] ?? ''));
    if ('unverified-restamp' === $provenance) {
        $warnings[] = 'manifest provenance=unverified-restamp — the fingerprint was stamped without a build receipt, so the committed gen-0 blobs are NOT known to come from these sources (#22642)';
        $haveFp = strtolower(trim((string) ($manifest['lowering_source_fingerprint'] ?? '')));
        if ('' !== $haveFp) {
            try {
                $liveFp = strtolower(bootstrap_gen0_manifest_live_lowering_fingerprint($root));
                if ($haveFp !== $liveFp) {
                    $warnings[] = "lowering_source_fingerprint drifts under unverified-restamp (manifest {$haveFp}, live {$liveFp}) — rebuild via script/bootstrap-refresh-gen0-sidecar.sh; do not restamp (#21905, #10533)";
                }
            } catch (\Throwable) {
            }
        }
    } elseif ('' === $provenance && '' !== $have) {
        $warnings[] = 'manifest has no provenance field — fingerprint predates build-receipt verification (#22642)';
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
 * source onto possibly-ancient blobs (false provenance). Provenance is set only by
 * {@see bootstrap_gen0_manifest_stamp_lowering_fingerprint()}, which requires a build
 * receipt covering the committed blobs (#21905, #22642).
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
 * Path of the build receipt a verified-fresh gen-0 link leaves behind (#22642).
 */
function bootstrap_gen0_build_receipt_path(string $root): string
{
    return rtrim($root, '/').'/build/.gen0-build-receipt.json';
}

/**
 * Committed asset label => the build/ artifact a fresh link copies into it (#22642).
 *
 * @return array<string, string>
 */
function bootstrap_gen0_build_receipt_sources(): array
{
    return [
        'driver' => 'build/bin-compile-aot',
        'compiler_minimal_sidecar' => 'build/.m3_compiler_minimal_aot_blob',
        'compiler_lib_sidecar' => 'build/.m3_compiler_lib_aot_blob',
    ];
}

/**
 * The spine binary a full link produces — link evidence that copying committed blobs cannot fake.
 *
 * Uncommitted and gitignored, so its presence means this tree actually ran the spine link.
 */
function bootstrap_gen0_build_receipt_link_evidence_path(string $root): string
{
    return rtrim($root, '/').'/build/selfhost-lib-spine-smoke';
}

/**
 * Record what a just-completed link produced, keyed by the fingerprint live at link time.
 *
 * The receipt is what makes provenance checkable: stamping later requires a receipt whose
 * hashes still match the committed blobs, so restamping a fingerprint over bytes that were
 * never rebuilt fails instead of silently claiming fresh provenance (#22642, #21905).
 *
 * Scope of the guarantee: this stops the reflexive restamp loop (fingerprint drifts red →
 * run the one-liner → gate green), which is what actually happened 225 times. It is not a
 * defence against deliberate forgery — anyone willing to stage a build/ tree by hand can
 * still write a receipt. It makes that an explicit, greppable act rather than the documented fix.
 *
 * @return array<string, mixed>
 */
function bootstrap_gen0_write_build_receipt(string $root, ?string $fingerprint = null): array
{
    $fp = $fingerprint;
    if (null === $fp || '' === $fp) {
        $fp = bootstrap_gen0_manifest_live_lowering_fingerprint($root);
    }
    $fp = strtolower(trim($fp));
    if (!preg_match('/^[a-f0-9]{64}$/', $fp)) {
        throw new \RuntimeException('invalid lowering_source_fingerprint (want 64 hex chars)');
    }

    $evidenceAbs = bootstrap_gen0_build_receipt_link_evidence_path($root);
    if (!is_file($evidenceAbs)) {
        throw new \RuntimeException(
            'gen-0 build receipt: missing '.$evidenceAbs
            .' — a receipt may only be written by a tree that ran the spine link (#22642)'
        );
    }
    $evidenceSha = hash_file('sha256', $evidenceAbs);
    if (!\is_string($evidenceSha)) {
        throw new \RuntimeException('gen-0 build receipt: cannot hash '.$evidenceAbs);
    }

    $artifacts = [];
    foreach (bootstrap_gen0_build_receipt_sources() as $label => $rel) {
        $abs = rtrim($root, '/').'/'.$rel;
        if (!is_file($abs) || !is_readable($abs)) {
            continue;
        }
        $sha = hash_file('sha256', $abs);
        if (is_string($sha)) {
            $artifacts[$label] = ['source' => $rel, 'sha256' => strtolower($sha)];
        }
    }
    if ([] === $artifacts) {
        throw new \RuntimeException('gen-0 build receipt: no build/ artifacts to record (link first)');
    }

    $receipt = [
        'version' => 1,
        'lowering_source_fingerprint' => $fp,
        'generated_at' => gmdate('c'),
        'link_evidence' => [
            'source' => 'build/selfhost-lib-spine-smoke',
            'sha256' => strtolower($evidenceSha),
        ],
        'artifacts' => $artifacts,
    ];

    $path = bootstrap_gen0_build_receipt_path($root);
    $dir = \dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException('failed creating '.$dir);
    }
    $encoded = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if (false === file_put_contents($path, $encoded)) {
        throw new \RuntimeException('failed writing '.$path);
    }

    return $receipt;
}

/**
 * @return array<string, mixed>|null
 */
function bootstrap_gen0_read_build_receipt(string $root): ?array
{
    $path = bootstrap_gen0_build_receipt_path($root);
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/**
 * Why the committed blobs cannot honestly carry $fingerprint as verified-fresh provenance.
 *
 * Empty list means a link at this fingerprint produced exactly the bytes now committed (#22642).
 *
 * @return list<string>
 */
function bootstrap_gen0_build_receipt_errors(string $root, string $fingerprint): array
{
    $receipt = bootstrap_gen0_read_build_receipt($root);
    if (null === $receipt) {
        return [
            'no gen-0 build receipt at '.bootstrap_gen0_build_receipt_path($root)
                .' — provenance is only stampable by the link that produced these blobs',
        ];
    }

    $want = strtolower(trim($fingerprint));
    $have = strtolower(trim((string) ($receipt['lowering_source_fingerprint'] ?? '')));
    if ($have !== $want) {
        return ["build receipt records fingerprint {$have}, not the {$want} being stamped — relink against current sources"];
    }

    $evidence = $receipt['link_evidence'] ?? null;
    if (!\is_array($evidence) || !isset($evidence['sha256'])) {
        return ['build receipt carries no link_evidence — rewrite it from a tree that ran the spine link (#22642)'];
    }
    $evidenceAbs = bootstrap_gen0_build_receipt_link_evidence_path($root);
    if (!is_file($evidenceAbs)) {
        return ['build receipt references '.$evidenceAbs.', which is gone — relink before stamping'];
    }
    $evidenceSha = hash_file('sha256', $evidenceAbs);
    if (!\is_string($evidenceSha) || strtolower($evidenceSha) !== strtolower((string) $evidence['sha256'])) {
        return ['build receipt link_evidence does not match the spine binary now in build/ — relink before stamping'];
    }

    $artifacts = $receipt['artifacts'] ?? null;
    if (!\is_array($artifacts)) {
        return ['build receipt has no artifacts block'];
    }

    $errors = [];
    foreach (bootstrap_gen0_manifest_tracked_assets() as $label => $asset) {
        $abs = rtrim($root, '/').'/'.$asset['rel'];
        if (!is_file($abs)) {
            $errors[] = "{$label}: missing {$asset['rel']}";

            continue;
        }
        $entry = $artifacts[$label] ?? null;
        if (!\is_array($entry) || !isset($entry['sha256'])) {
            $errors[] = "{$label}: build receipt does not cover {$asset['rel']}";

            continue;
        }
        $onDisk = hash_file('sha256', $abs);
        if (!\is_string($onDisk) || strtolower($onDisk) !== strtolower((string) $entry['sha256'])) {
            $errors[] = "{$label}: {$asset['rel']} differs from the artifact that link produced — these bytes were not rebuilt";
        }
    }

    return $errors;
}

/**
 * Record lowering provenance after a verified-fresh copy into prelinked/bootstrap-gen0/ (#21905).
 *
 * Refuses unless a build receipt proves the committed blobs came from a link at $fingerprint
 * (#22642). BOOTSTRAP_GEN0_ALLOW_UNVERIFIED_STAMP=1 still permits a restamp, but records
 * `provenance: unverified-restamp` in the manifest so the claim is visible in the artifact
 * rather than implied by a green gate.
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
    if (null === $fp || '' === $fp) {
        $fp = bootstrap_gen0_manifest_live_lowering_fingerprint($root);
    }
    $fp = strtolower(trim($fp));
    if (!preg_match('/^[a-f0-9]{64}$/', $fp)) {
        throw new \RuntimeException('invalid lowering_source_fingerprint (want 64 hex chars)');
    }

    $receiptErrors = bootstrap_gen0_build_receipt_errors($root, $fp);
    if ([] !== $receiptErrors && '1' !== getenv('BOOTSTRAP_GEN0_ALLOW_UNVERIFIED_STAMP')) {
        throw new \RuntimeException(
            "refusing to stamp lowering_source_fingerprint without a matching build receipt (#22642):\n  - "
            .implode("\n  - ", $receiptErrors)
            ."\nRebuild via script/bootstrap-refresh-gen0-sidecar.sh, or set"
            .' BOOTSTRAP_GEN0_ALLOW_UNVERIFIED_STAMP=1 to record an explicitly unverified stamp.'
        );
    }

    $manifest['lowering_source_fingerprint'] = $fp;
    $manifest['provenance'] = [] === $receiptErrors ? 'verified-fresh' : 'unverified-restamp';
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
