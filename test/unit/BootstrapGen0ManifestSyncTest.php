<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Gen-0 argv driver manifest sync (#8713, #3053). */
final class BootstrapGen0ManifestSyncTest extends TestCase
{
    public function testGen0ManifestMatchesCommittedDriverBytes(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/check-bootstrap-gen0-manifest-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testInventoryArgvDriverSizeOkUsesManifestFloor(): void
    {
        $root = dirname(__DIR__, 2);
        $body = (string) file_get_contents($root.'/script/bootstrap-resolve-compile-invoke.sh');
        $this->assertStringContainsString('bootstrap_gen0_manifest_driver_min_bytes', $body);
        $this->assertStringContainsString('bootstrap_inventory_argv_driver_size_ok', $body);
    }

    public function testNorthStar5FastRunsGen0ManifestSyncWhenSeedPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/north-star5-verify.sh');
        $this->assertStringContainsString('check-bootstrap-gen0-manifest-sync.php', $script);
    }
}
