<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Gen-0 manifest refresh helpers (#8704, #8713). */
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
    }
}
