<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** development-status.md drift guard (issues #2039, #2067). */
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
        $this->assertStringContainsString('Shipped examples (000–006)', $body);
    }
}
