<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** development-status.md drift guard (issues #2039, #2067, #2145). */
final class DevelopmentStatusSyncTest extends TestCase
{
    public function testDevelopmentStatusSyncScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/check-development-status-sync.php';
        $this->assertFileExists($script);
    }

    public function testDevelopmentStatusSyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-development-status-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-development-status-sync: OK', implode("\n", $out));
    }

    public function testDevelopmentStatusLists006FileUploadWeb(): void
    {
        $path = dirname(__DIR__, 2).'/docs/pages/development-status.md';
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('006-FileUploadWeb', $body);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_SMOKE_GATE=1', $body);
        $this->assertStringContainsString('Shipped examples (000–009)', $body);
    }

    public function testDevelopmentStatusLists007ThrowsWeb(): void
    {
        $path = dirname(__DIR__, 2).'/docs/pages/development-status.md';
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('007-ThrowsWeb', $body);
        $this->assertStringContainsString('THROWS_WEB_SMOKE_GATE=1', $body);
        $this->assertStringContainsString('#2093', $body);
        $this->assertStringContainsString('#2101', $body);
    }

    public function testDevelopmentStatus007SyncPassesWithGateOn(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'DEVELOPMENT_STATUS_007_SYNC_GATE=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-development-status-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-development-status-sync: OK', implode("\n", $out));
    }

    public function testDevelopmentStatusLists009FastCGIWeb(): void
    {
        $path = dirname(__DIR__, 2).'/docs/pages/development-status.md';
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('009-FastCGIWeb', $body);
        $this->assertStringContainsString('FASTCGI_WEB_SMOKE_GATE', $body);
        $this->assertStringContainsString('#2331', $body);
        $this->assertStringContainsString('#173', $body);
    }

    public function testDevelopmentStatus009SyncScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/check-development-status-009-sync.php';
        $this->assertFileExists($script);
    }

    public function testDevelopmentStatus009SyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-development-status-009-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-development-status-009-sync: OK', implode("\n", $out));
    }
}
