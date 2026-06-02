<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapSelfhostGateTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testGateScriptDocumentsDockerExecAndMakeFreePath(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-gate.sh';
        $this->assertFileExists($script);
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('bootstrap-selfhost-gate', $body);
        $this->assertStringContainsString('bootstrap-selfhost-link.sh', $body);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $body);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $body);
        $this->assertStringContainsString('bootstrap-inventory.php', $body);
        $this->assertStringContainsString('docker-exec.sh', $body);
        $this->assertStringContainsString('#2674', $body);
        $this->assertStringContainsString('Do not nest', $body);
    }

    public function testSelfhostPreflightMentionsGateWrapper(): void
    {
        $body = (string) file_get_contents(self::$root.'/script/selfhost-preflight.sh');
        $this->assertStringContainsString('bootstrap-selfhost-gate.sh', $body);
        $this->assertStringContainsString('#2905', $body);
        $this->assertStringContainsString('#2674', $body);
    }

    public function testGateHelpPrintsUsageWithoutMake(): void
    {
        $cmd = 'bash '.escapeshellarg(self::$root.'/script/bootstrap-selfhost-gate.sh').' help 2>&1';
        exec($cmd, $lines, $exitCode);
        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('inventory-check', $out);
        $this->assertStringContainsString('docker-exec.sh', $out);
        $this->assertStringContainsString('make/php', $out);
    }
}
