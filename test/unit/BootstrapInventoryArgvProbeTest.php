<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group bootstrap */
final class BootstrapInventoryArgvProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testInventoryArgvProbeScriptExistsAndDocumentsEnv(): void
    {
        $script = self::$root.'/script/bootstrap-inventory-argv-probe.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('bootstrap-inventory-argv-probe:', $body);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1', $body);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_NO_EMIT_HELPER_SIDECAR=1', $body);
        $this->assertStringContainsString('-u PHP_COMPILER_EMIT_HELPER_LINK', $body);
        $this->assertStringContainsString('sidecar_free=', $body);
        $this->assertStringContainsString('#15604', $body);
        $this->assertStringContainsString('bootstrap-honest-compile-lib.sh', $body);
    }

    public function testMakefileExposesInventoryArgvProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-inventory-argv-probe:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-inventory-argv-probe.sh', $makefile);
    }

    public function testBootstrapDevWorkflowDocumentsInventoryArgvGap(): void
    {
        $doc = (string) file_get_contents(self::$root.'/docs/bootstrap-dev-workflow.md');
        $this->assertStringContainsString('#15604', $doc);
        $this->assertStringContainsString('bootstrap-inventory-argv-probe', $doc);
        $this->assertStringContainsString('sidecar_free=ok', $doc);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_NO_EMIT_HELPER_SIDECAR', $doc);
    }

    public function testJitDocumentsInventoryNoEmitHelperSidecarProbeEnv(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('shouldSkipM3InventoryEmitHelperSidecarsForProbe', $jit);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_NO_EMIT_HELPER_SIDECAR', $jit);
        $this->assertStringContainsString('#15604', $jit);
    }

    public function testInventoryArgvProbeCheckModeExitsZero(): void
    {
        $script = self::$root.'/script/bootstrap-inventory-argv-probe.sh';
        $cmd = escapeshellarg($script).' --check 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertStringContainsString('check OK', implode("\n", $lines));
    }
}
