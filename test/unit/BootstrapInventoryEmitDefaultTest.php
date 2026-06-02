<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #3024: default-on inventory compile_driver for M3 emit-helper link. */
final class BootstrapInventoryEmitDefaultTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testInventoryEmitDefaultHelperExists(): void
    {
        $script = self::$root.'/script/bootstrap-inventory-emit-default.sh';
        $this->assertFileExists($script);
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('bootstrap_resolve_inventory_emit_driver', $body);
        $this->assertStringContainsString('BOOTSTRAP_M3_EMIT_HELPER_TU', $body);
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $body);
    }

    public function testCompilerUnitProbeUsesInventoryEmitDefaultHelper(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-compiler-unit-probe.sh');
        $this->assertStringContainsString('bootstrap_resolve_inventory_emit_driver', $script);
        $this->assertStringNotContainsString('default_inventory_emit_driver=0', $script);
    }

    public function testLoopGen1LinkUsesInventoryEmitDefaultHelper(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-gen1-link.sh');
        $this->assertStringContainsString('bootstrap_resolve_inventory_emit_driver', $script);
        $this->assertStringContainsString('inventory compile_driver by default', $script);
    }
}
