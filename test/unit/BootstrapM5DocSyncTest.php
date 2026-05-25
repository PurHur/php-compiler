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

    public function testCompileSmokeM3EmitDocumentedAsAllow(): void
    {
        require_once self::$root.'/script/bootstrap-m5-doc-sync.php';

        $fromDoc = bootstrap_m5_doc_parse_allow_deny(self::$root.'/docs/bootstrap-m5-fast-path.md');
        $this->assertContains('\\bootstrapaot\\compile_smoke_m3_emit', $fromDoc['allow']);
        $this->assertNotContains('\\bootstrapaot\\compile_smoke_m3_emit', $fromDoc['deny']);
    }

    public function testBootstrapM5DocSyncPassesOnMaster(): void
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$root.'/script/check-bootstrap-m5-doc-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
