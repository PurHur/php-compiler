<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Bootstrap inventory lint snapshot drift guard (#2210). */
final class BootstrapInventoryLintSyncTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testBootstrapInventoryLintSyncArtifactsExist(): void
    {
        $this->assertFileExists(self::$root.'/script/bootstrap-inventory-lint-sync-lib.php');
        $this->assertFileExists(self::$root.'/script/check-bootstrap-inventory-lint-sync.php');
        $this->assertFileExists(self::$root.'/script/bootstrap-inventory-lint-snapshot.php');
        $this->assertFileExists(self::$root.'/docs/bootstrap-inventory-lint-snapshot.json');
    }

    public function testBootstrapInventoryLintSyncPassesOnMaster(): void
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$root.'/script/check-bootstrap-inventory-lint-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-bootstrap-inventory-lint-sync: OK', implode("\n", $out));
    }
}
