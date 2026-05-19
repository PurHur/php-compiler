<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap self-host inventory (issue #212 Phase A).
 */
final class BootstrapInventoryTest extends TestCase
{
    public function testInventoryScriptRuns(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-inventory.php').' --json 2>/dev/null';
        $json = shell_exec($cmd);
        $this->assertIsString($json);
        $report = json_decode($json, true);
        $this->assertIsArray($report);
        $this->assertSame('bin/vm.php', $report['entry']);
        $this->assertGreaterThan(100, $report['totals']['files']);
        $this->assertArrayHasKey('lib/Runtime.php', $report['files']);
        $this->assertArrayHasKey('lib/Compiler.php', $report['files']);
    }

    public function testInventoryDocIsFresh(): void
    {
        $root = dirname(__DIR__, 2);
        $doc = $root.'/docs/bootstrap-inventory.md';
        $this->assertFileExists($doc);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-inventory.php').' --check 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
