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

    public function testStampLoweringFingerprintRoundTrip(): void
    {
        require_once self::$root.'/script/bootstrap-gen0-manifest-lib.php';

        $manifestPath = bootstrap_gen0_manifest_path(self::$root);
        $stampPath = self::$root.'/prelinked/bootstrap-gen0/.bootstrap_lowering_source.sha';
        $origManifest = (string) file_get_contents($manifestPath);
        $origStamp = is_readable($stampPath) ? (string) file_get_contents($stampPath) : null;

        try {
            $fake = str_repeat('ab', 32);
            $stamped = bootstrap_gen0_manifest_stamp_lowering_fingerprint(self::$root, $fake);
            $this->assertSame($fake, $stamped['lowering_source_fingerprint'] ?? null);
            $read = bootstrap_gen0_manifest_read(self::$root);
            $this->assertSame($fake, $read['lowering_source_fingerprint'] ?? null);
            $this->assertSame($fake, trim((string) file_get_contents($stampPath)));

            // Size/sha refresh must preserve the stamped claim.
            bootstrap_gen0_manifest_refresh_from_disk(self::$root);
            $preserved = bootstrap_gen0_manifest_read(self::$root);
            $this->assertSame($fake, $preserved['lowering_source_fingerprint'] ?? null);

            $errors = bootstrap_gen0_manifest_lowering_fingerprint_errors(self::$root);
            $this->assertNotSame([], $errors, 'fake fingerprint must fail live check');
        } finally {
            file_put_contents($manifestPath, $origManifest);
            if (null === $origStamp) {
                @unlink($stampPath);
            } else {
                file_put_contents($stampPath, $origStamp);
            }
        }
    }

    public function testStampLiveFingerprintRequiresBuildStamp(): void
    {
        require_once self::$root.'/script/bootstrap-gen0-manifest-lib.php';

        $manifestPath = bootstrap_gen0_manifest_path(self::$root);
        $stampPath = self::$root.'/prelinked/bootstrap-gen0/.bootstrap_lowering_source.sha';
        $buildStamp = self::$root.'/build/.bootstrap_lowering_source.sha';
        $origManifest = (string) file_get_contents($manifestPath);
        $origStamp = is_readable($stampPath) ? (string) file_get_contents($stampPath) : null;
        $origBuild = is_readable($buildStamp) ? (string) file_get_contents($buildStamp) : null;

        try {
            @unlink($buildStamp);
            try {
                bootstrap_gen0_manifest_stamp_lowering_fingerprint(self::$root);
                $this->fail('expected RuntimeException for restamp without build stamp');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('refusing to stamp live lowering_source_fingerprint', $e->getMessage());
            }
        } finally {
            file_put_contents($manifestPath, $origManifest);
            if (null === $origStamp) {
                @unlink($stampPath);
            } else {
                file_put_contents($stampPath, $origStamp);
            }
            if (null === $origBuild) {
                @unlink($buildStamp);
            } else {
                if (!is_dir(dirname($buildStamp))) {
                    mkdir(dirname($buildStamp), 0777, true);
                }
                file_put_contents($buildStamp, $origBuild);
            }
        }
    }

    public function testRefreshScriptAllowsStaleSeedForLink(): void
    {
        $body = (string) file_get_contents(self::$root.'/script/bootstrap-refresh-gen0-sidecar.sh');
        $this->assertStringContainsString('BOOTSTRAP_ALLOW_STALE_COMPILED_DRIVER=1', $body);
        $this->assertStringContainsString('stale gen-0 seed allowed for refresh', $body);
        $this->assertStringContainsString('bootstrap_compiler_lib_honest_zend_compile', $body);
        $this->assertStringContainsString('refusing restamp-only fingerprint update', $body);
        $this->assertStringContainsString('#22642', $body);
    }
}
