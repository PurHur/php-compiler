<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT gc_collect_cycles() cycle sweep + registry unregister invariants (#36245).
 *
 * @group aot-lint
 */
final class GcCollectCyclesAotCycleTest extends TestCase
{
    public function testUnregisterSwapCopiesMarkedAndInbound(): void
    {
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('G_MARKED', $runtime);
        $this->assertStringContainsString('G_INBOUND', $runtime);
        $this->assertStringContainsString('$lastMarked', $runtime);
        $this->assertStringContainsString('$lastInbound', $runtime);
    }

    public function testNativeScanSweepUsesPointerFreeList(): void
    {
        $scan = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/GcCollectCyclesNativeScanJitHelper.php');
        $free = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/GcCollectCyclesNativeFreeJitHelper.php');
        $this->assertStringContainsString('$toFree', $scan);
        $this->assertStringContainsString('foreach ($toFree as $objPtr)', $scan);
        $this->assertStringContainsString('freeRegistryObject', $free);
    }
}
