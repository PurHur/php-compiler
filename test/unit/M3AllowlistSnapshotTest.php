<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * M3 compile-driver allowlist drift guard (issues #1768, #1905).
 */
final class M3AllowlistSnapshotTest extends TestCase
{
    public function testBootstrapM3AllowlistSnapshotScriptsExist(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/script/bootstrap-m3-allowlist-snapshot.php');
        $this->assertFileExists($root.'/script/check-m3-allowlist-snapshot.php');
        $this->assertFileExists($root.'/script/m3-allowlist-snapshot.txt');
    }

    public function testBootstrapM3AllowlistMatchesJit(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/script/bootstrap-m3-allowlist-snapshot.php';

        $lines = m3_allowlist_snapshot_lines($root);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^(allow|deny):/', $line, $line);
        }

        $jit = (string) file_get_contents($root.'/lib/JIT.php');
        $this->assertStringContainsString('isM3CompileDriverRealLoweringName', $jit);
        $this->assertStringContainsString('m3CompileDriverSpineDenyNames', $jit);
    }

    public function testM3AllowlistSnapshotCheckPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/check-m3-allowlist-snapshot.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
