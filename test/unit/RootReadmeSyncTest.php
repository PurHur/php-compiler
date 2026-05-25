<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Root README.md drift guard (issue #1832).
 */
final class RootReadmeSyncTest extends TestCase
{
    public function testRootReadmeSyncScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/check-root-readme-sync.php';
        $this->assertFileExists($script);
    }

    public function testRootReadmeSyncPassesAfterNorthStarDocRefresh(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-root-readme-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-root-readme-sync: OK', implode("\n", $out));
    }

    public function testRootReadmeLists005SessionsWeb(): void
    {
        $readme = dirname(__DIR__, 2).'/README.md';
        $this->assertFileExists($readme);
        $body = (string) file_get_contents($readme);
        $this->assertStringContainsString('005-SessionsWeb', $body);
        $this->assertStringContainsString('examples/005-SessionsWeb/', $body);
    }

    public function testRootReadmeLists006FileUploadWeb(): void
    {
        $readme = dirname(__DIR__, 2).'/README.md';
        $this->assertFileExists($readme);
        $body = (string) file_get_contents($readme);
        $this->assertStringContainsString('006-FileUploadWeb', $body);
        $this->assertStringContainsString('examples/006-FileUploadWeb/', $body);
    }

    public function testRootReadmeSync006StaleGatePassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $env = 'ROOT_README_006_SYNC_GATE=1';
        $cmd = $env.' '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-root-readme-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-root-readme-sync: OK', implode("\n", $out));
    }
}
