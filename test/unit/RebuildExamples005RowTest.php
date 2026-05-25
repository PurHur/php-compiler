<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * 005-SessionsWeb README benchmark row drift guard (issue #1930).
 */
final class RebuildExamples005RowTest extends TestCase
{
    public function testRebuildExamples005RowCheckerPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-rebuild-examples-005-row.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testRebuildExamples005RowCheckerScriptDocumentsPolicy(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/script/check-rebuild-examples-005-row.php');
        $this->assertNotFalse($script);
        $this->assertStringContainsString('BENCH_SESSIONSWEB', $script);
        $this->assertStringContainsString('SESSIONSWEB_LINT_GATE', $script);
        $this->assertStringContainsString('005-SessionsWeb', $script);
        $this->assertStringContainsString('benchmark table start', $script);
        $this->assertStringContainsString('#1891', $script);
        $this->assertStringContainsString('BENCH_SESSIONSWEB_AOT', $script);
        $this->assertStringContainsString('sessions_web_aot_execute_probe', $script);
        $this->assertStringContainsString('#1973', $script);
    }
}
