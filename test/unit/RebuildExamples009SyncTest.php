<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * 009-FastCGIWeb README benchmark row drift guard (issue #2370).
 */
final class RebuildExamples009SyncTest extends TestCase
{
    public function testRebuildExamples009SyncCheckerPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-rebuild-examples-009-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testRebuildExamples009SyncCheckerScriptDocumentsPolicy(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/script/check-rebuild-examples-009-sync.php');
        $this->assertNotFalse($script);
        $this->assertStringContainsString('BENCH_FASTCGIWEB', $script);
        $this->assertStringContainsString('FASTCGIWEB_LINT_GATE', $script);
        $this->assertStringContainsString('009-FastCGIWeb', $script);
        $this->assertStringContainsString('benchmark table start', $script);
        $this->assertStringContainsString('BENCH_FASTCGIWEB_AOT', $script);
        $this->assertStringContainsString('fastcgi_web_aot_execute_probe', $script);
        $this->assertStringContainsString('#2370', $script);
    }
}
