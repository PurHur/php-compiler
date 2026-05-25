<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Bootstrap M5 fast-path doc vs M3 allowlist snapshot drift guard (#1984). */
final class BootstrapM5DocSyncTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testBootstrapM5DocSyncScriptsExist(): void
    {
        $this->assertFileExists(self::$root.'/script/bootstrap-m5-doc-sync.php');
        $this->assertFileExists(self::$root.'/script/check-bootstrap-m5-doc-sync.php');
        $this->assertFileExists(self::$root.'/docs/bootstrap-m5-fast-path.md');
        $this->assertFileExists(self::$root.'/script/m3-allowlist-snapshot.txt');
    }

    public function testCompileSmokeM3EmitDocumentedInFastPathDoc(): void
    {
        $doc = (string) file_get_contents(self::$root.'/docs/bootstrap-m5-fast-path.md');
        $this->assertStringContainsString('compile_smoke_m3_emit', $doc);
        $this->assertStringContainsString('compile_smoke_m3_emit_native_entry.php', $doc);
    }

    public function testBootstrapM5DocSyncPassesOnMaster(): void
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$root.'/script/check-bootstrap-m5-doc-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
