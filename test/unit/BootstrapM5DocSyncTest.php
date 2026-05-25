<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** bootstrap-m5-fast-path.md vs m3-allowlist-snapshot drift guard (#1984). */
final class BootstrapM5DocSyncTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testCheckerScriptExists(): void
    {
        $this->assertFileExists(self::$root.'/script/check-bootstrap-m5-doc-sync.php');
        $this->assertFileExists(self::$root.'/docs/bootstrap-m5-fast-path.md');
        $this->assertFileExists(self::$root.'/script/m3-allowlist-snapshot.txt');
    }

    public function testDocAndSnapshotInSyncOnMaster(): void
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$root.'/script/check-bootstrap-m5-doc-sync.php');
        exec($cmd.' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('check-bootstrap-m5-doc-sync: OK', implode("\n", $output));
    }

    public function testCompileSmokeM3EmitDocumentedAsAllow(): void
    {
        require_once self::$root.'/script/bootstrap-m3-allowlist.php';

        $lists = bootstrap_m3_allowlist_read_snapshot(self::$root.'/script/m3-allowlist-snapshot.txt');
        $this->assertContains('\\bootstrapaot\\compile_smoke_m3_emit', $lists['allow']);

        $doc = (string) file_get_contents(self::$root.'/docs/bootstrap-m5-fast-path.md');
        $this->assertStringContainsString('`compile_smoke_m3_emit`', $doc);
        $this->assertStringContainsString('Real-lowered', $doc);
    }
}
