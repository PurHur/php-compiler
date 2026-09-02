<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Gen-0 manifest refresh helpers (#8704, #8713, #21905). */
final class BootstrapGen0ManifestRefreshTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testRefreshFromDiskMatchesSyncCheck(): void
    {
        require_once self::$root.'/script/bootstrap-gen0-manifest-lib.php';

        $manifestPath = bootstrap_gen0_manifest_path(self::$root);
        $origManifest = (string) file_get_contents($manifestPath);

        try {
            $before = bootstrap_gen0_manifest_read(self::$root);
            $this->assertIsArray($before);

            bootstrap_gen0_manifest_refresh_from_disk(self::$root);

            $this->assertSame([], bootstrap_gen0_manifest_sync_errors(self::$root));

            $after = bootstrap_gen0_manifest_read(self::$root);
            $this->assertIsArray($after);

            // Idempotent size/sha when blobs unchanged (generated_at may move).
            bootstrap_gen0_manifest_refresh_from_disk(self::$root);
            $again = bootstrap_gen0_manifest_read(self::$root);
            $this->assertSame($after['sha256_driver'] ?? null, $again['sha256_driver'] ?? null);
            $this->assertSame($after['size_bytes_compiler_lib_sidecar'] ?? null, $again['size_bytes_compiler_lib_sidecar'] ?? null);
            $this->assertSame($after['sha256_compiler_lib_sidecar'] ?? null, $again['sha256_compiler_lib_sidecar'] ?? null);
        } finally {
            file_put_contents($manifestPath, $origManifest);
        }
    }

    public function testRefreshFromDiskDoesNotInventLoweringFingerprint(): void
    {
        require_once self::$root.'/script/bootstrap-gen0-manifest-lib.php';

        $manifestPath = bootstrap_gen0_manifest_path(self::$root);
        $origManifest = (string) file_get_contents($manifestPath);

        try {
            $before = bootstrap_gen0_manifest_read(self::$root);
            $this->assertIsArray($before);
            $hadKey = array_key_exists('lowering_source_fingerprint', $before);
            $beforeFp = $before['lowering_source_fingerprint'] ?? null;

            bootstrap_gen0_manifest_refresh_from_disk(self::$root);
            $after = bootstrap_gen0_manifest_read(self::$root);
            $this->assertIsArray($after);

            if ($hadKey) {
                $this->assertSame($beforeFp, $after['lowering_source_fingerprint'] ?? null);
            } else {
                $this->assertArrayNotHasKey('lowering_source_fingerprint', $after);
            }

            // Must not silently stamp today's live fingerprint onto possibly-stale blobs.
            $live = bootstrap_gen0_manifest_live_lowering_fingerprint(self::$root);
            if (!$hadKey) {
                $this->assertNotSame($live, $after['lowering_source_fingerprint'] ?? null);
            }
        } finally {
            file_put_contents($manifestPath, $origManifest);
        }
    }

    public function testDowngradeStaleVerifiedFreshWhenLoweringDriftsButBlobsMatch(): void
    {
        require_once self::$root.'/script/bootstrap-gen0-manifest-lib.php';

        $manifestPath = bootstrap_gen0_manifest_path(self::$root);
        $origManifest = (string) file_get_contents($manifestPath);

        try {
            $manifest = bootstrap_gen0_manifest_read(self::$root);
            $this->assertIsArray($manifest);
            $this->assertSame([], bootstrap_gen0_manifest_blob_sync_errors(self::$root, $manifest));

            $live = bootstrap_gen0_manifest_live_lowering_fingerprint(self::$root);
            $stale = str_repeat('cd', 32);
            if ($stale === $live) {
                $stale = str_repeat('ef', 32);
            }

            $manifest['provenance'] = 'verified-fresh';
            $manifest['lowering_source_fingerprint'] = $stale;
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            $this->assertNotSame([], bootstrap_gen0_manifest_lowering_fingerprint_errors(self::$root));
            $this->assertTrue(bootstrap_gen0_manifest_downgrade_stale_verified_fresh_provenance(self::$root));
            $downgraded = bootstrap_gen0_manifest_read(self::$root);
            $this->assertSame('unverified-restamp', $downgraded['provenance'] ?? null);
            $this->assertSame($stale, $downgraded['lowering_source_fingerprint'] ?? null);
            $this->assertSame([], bootstrap_gen0_manifest_lowering_fingerprint_errors(self::$root));
        } finally {
            file_put_contents($manifestPath, $origManifest);
        }
    }

    public function testStampLoweringFingerprintRoundTrip(): void
    {
        require_once self::$root.'/script/bootstrap-gen0-manifest-lib.php';

        $manifestPath = bootstrap_gen0_manifest_path(self::$root);
        $stampPath = self::$root.'/prelinked/bootstrap-gen0/.bootstrap_lowering_source.sha';
        $origManifest = (string) file_get_contents($manifestPath);
        $origStamp = is_readable($stampPath) ? (string) file_get_contents($stampPath) : null;

        try {
            $fake = str_repeat('ab', 32);

            // No build receipt covers the committed blobs at $fake, so the honest path refuses (#22642).
            try {
                bootstrap_gen0_manifest_stamp_lowering_fingerprint(self::$root, $fake);
                $this->fail('stamp without a build receipt must throw');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('build receipt', $e->getMessage());
            }

            // With a matching receipt, stamp succeeds (#36218 — no unverified override).
            $receiptPath = self::$root.'/build/.gen0-build-receipt.json';
            if (is_readable($receiptPath)) {
                $receipt = json_decode((string) file_get_contents($receiptPath), true);
                $this->assertIsArray($receipt);
                $receiptFp = strtolower((string) ($receipt['lowering_source_fingerprint'] ?? ''));
                if ('' !== $receiptFp) {
                    $stamped = bootstrap_gen0_manifest_stamp_lowering_fingerprint(self::$root, $receiptFp);
                    $this->assertSame($receiptFp, $stamped['lowering_source_fingerprint'] ?? null);
                    $this->assertSame('verified-fresh', $stamped['provenance'] ?? null);
                    $read = bootstrap_gen0_manifest_read(self::$root);
                    $this->assertSame($receiptFp, $read['lowering_source_fingerprint'] ?? null);
                    $this->assertSame($receiptFp, trim((string) file_get_contents($stampPath)));

                    bootstrap_gen0_manifest_refresh_from_disk(self::$root);
                    $preserved = bootstrap_gen0_manifest_read(self::$root);
                    $this->assertSame($receiptFp, $preserved['lowering_source_fingerprint'] ?? null);
                }
            }
        } finally {
            file_put_contents($manifestPath, $origManifest);
            if (null === $origStamp) {
                @unlink($stampPath);
            } else {
                file_put_contents($stampPath, $origStamp);
            }
        }
    }
}
