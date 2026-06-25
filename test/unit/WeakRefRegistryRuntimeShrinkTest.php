<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\WeakRefRegistryJitHelper;
use PHPUnit\Framework\TestCase;

/** WeakRefRegistry JIT routes through WeakRefRegistryJitHelper PHP not LLVM globals (#9191). */
final class WeakRefRegistryRuntimeShrinkTest extends TestCase
{
    public function testWeakRefRegistryRuntimeUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/WeakRefRegistryRuntime.php');
        $this->assertStringContainsString('WeakRefRegistryJitHelper', $source);
        $this->assertStringNotContainsString('phpc_wr_ref_count', $source);
        $this->assertStringNotContainsString('ensureGlobals', $source);
        $this->assertStringNotContainsString('refEntryPtr', $source);
        $this->assertStringNotContainsString('mapEntryPtr', $source);
        $this->assertStringContainsString('sext($i, $i64)', $source);
        $this->assertStringContainsString("appendBasicBlock('wr_clear_refs_do')", $source);
    }

    public function testWeakRefRegistryJitHelperRegistryRoundtrip(): void
    {
        WeakRefRegistryJitHelper::resetForTest();
        $target = 0x1000;
        $slot = 0x2000;
        WeakRefRegistryJitHelper::registerRef($target, $slot);
        $this->assertSame(1, WeakRefRegistryJitHelper::refCount());
        $this->assertSame($target, WeakRefRegistryJitHelper::refTargetPtr(0));
        $this->assertSame($slot, WeakRefRegistryJitHelper::refSlotPtr(0));

        $key = WeakRefRegistryJitHelper::formatObjectKey($target);
        $this->assertSame('o:1000', $key);
        $this->assertSame($target, WeakRefRegistryJitHelper::mapKeyToObjectPtr($key));

        WeakRefRegistryJitHelper::registerMap($target, 0x3000, $key);
        $this->assertSame(1, WeakRefRegistryJitHelper::mapCount());
        $this->assertSame($target, WeakRefRegistryJitHelper::mapTargetPtr(0));
        $this->assertSame(0x3000, WeakRefRegistryJitHelper::mapHtPtr(0));
        $this->assertSame($key, WeakRefRegistryJitHelper::mapKey(0));

        WeakRefRegistryJitHelper::unregisterMap($target, 0x3000, $key);
        $this->assertSame(0, WeakRefRegistryJitHelper::mapTargetPtr(0));
        $this->assertSame(0, WeakRefRegistryJitHelper::mapHtPtr(0));
        $this->assertSame('', WeakRefRegistryJitHelper::mapKey(0));

        WeakRefRegistryJitHelper::reset();
        $this->assertSame(0, WeakRefRegistryJitHelper::refCount());
        $this->assertSame(0, WeakRefRegistryJitHelper::mapCount());
    }
}
