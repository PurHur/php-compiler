<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\WeakRefRegistryJitHelper;
use PHPUnit\Framework\TestCase;

/** WeakRefRegistry JIT routes through WeakRefRegistryJitHelper via JitVmHelperLink (#9191, #26028). */
final class WeakRefRegistryRuntimeShrinkTest extends TestCase
{
    public function testWeakRefRegistryRuntimeUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/WeakRefRegistryRuntime.php');
        $this->assertStringContainsString('WeakRefRegistryJitHelper', $source);
        // Ref + map register/clear use LLVM globals for AOT standalone (#26795, #27621).
        $this->assertStringContainsString('phpc_wr_ref_count', $source);
        $this->assertStringContainsString('phpc_wr_map_count', $source);
        $this->assertStringContainsString('emitClearMapLoop', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('ensureGlobals', $source);
        $this->assertStringNotContainsString('refEntryPtr', $source);
        $this->assertStringNotContainsString('mapEntryPtr', $source);
        $this->assertStringNotContainsString('wr_reg_ref_bridge_check_max', $source);
        $this->assertStringNotContainsString('wr_reg_map_bridge_check_key', $source);
        $this->assertStringNotContainsString('emitClearRefLoop', $source);
        $this->assertStringNotContainsString('wr_clear_refs_do', $source);
    }

    public function testWeakRefRegistryJitHelperRegisterGuards(): void
    {
        WeakRefRegistryJitHelper::resetForTest();
        WeakRefRegistryJitHelper::registerRef(0, 0x100);
        $this->assertSame(0, WeakRefRegistryJitHelper::refCount());
        WeakRefRegistryJitHelper::registerRef(0x100, 0);
        $this->assertSame(0, WeakRefRegistryJitHelper::refCount());
        WeakRefRegistryJitHelper::registerRef(0x100, 0x200);
        $this->assertSame(1, WeakRefRegistryJitHelper::refCount());

        WeakRefRegistryJitHelper::registerMap(0, 0x300, 'k');
        $this->assertSame(0, WeakRefRegistryJitHelper::mapCount());
        WeakRefRegistryJitHelper::registerMap(0x100, 0, 'k');
        $this->assertSame(0, WeakRefRegistryJitHelper::mapCount());
        WeakRefRegistryJitHelper::registerMap(0x100, 0x300, '');
        $this->assertSame(0, WeakRefRegistryJitHelper::mapCount());
        WeakRefRegistryJitHelper::registerMap(0x100, 0x300, 'k');
        $this->assertSame(1, WeakRefRegistryJitHelper::mapCount());
    }

    public function testWeakRefRegistryJitHelperRegistryRoundtrip(): void
    {
        WeakRefRegistryJitHelper::resetForTest();
        $target = 0x1000;
        $slot = 0x2000;
        WeakRefRegistryJitHelper::appendRefEntry($target, $slot);
        $this->assertSame(1, WeakRefRegistryJitHelper::refCount());
        $this->assertSame($target, WeakRefRegistryJitHelper::refTargetPtr(0));
        $this->assertSame($slot, WeakRefRegistryJitHelper::refSlotPtr(0));

        $key = WeakRefRegistryJitHelper::formatObjectKey($target);
        $this->assertSame('o:1000', $key);
        $this->assertSame($target, WeakRefRegistryJitHelper::mapKeyToObjectPtr($key));

        WeakRefRegistryJitHelper::appendMapEntry($target, 0x3000, $key);
        $this->assertSame(1, WeakRefRegistryJitHelper::mapCount());
        $this->assertSame($target, WeakRefRegistryJitHelper::mapTargetPtr(0));
        $this->assertSame(0x3000, WeakRefRegistryJitHelper::mapHtPtr(0));
        $this->assertSame($key, WeakRefRegistryJitHelper::mapKey(0));

        WeakRefRegistryJitHelper::clearMapEntry(0);
        $this->assertSame(0, WeakRefRegistryJitHelper::mapTargetPtr(0));
        $this->assertSame(0, WeakRefRegistryJitHelper::mapHtPtr(0));
        $this->assertSame('', WeakRefRegistryJitHelper::mapKey(0));

        WeakRefRegistryJitHelper::reset();
        $this->assertSame(0, WeakRefRegistryJitHelper::refCount());
        $this->assertSame(0, WeakRefRegistryJitHelper::mapCount());
    }

    /** Phantom JIT\Variable::getValue() abort (#21109). */
    public function testWeakRefNativeOpsDoNotCallPhantomGetValue(): void
    {
        $weak = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/WeakRefNativeOpsJit.php');
        $gc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesNativeOpsJit.php');
        $this->assertStringNotContainsString('->getValue()', $weak);
        $this->assertStringNotContainsString('->getValue()', $gc);
        $this->assertStringContainsString('i64FromVar', $weak);
        $this->assertStringContainsString('i64FromVar', $gc);
        $this->assertStringContainsString('JitNestedHelperCoerce::i64ToTypedPtr', $weak);
        $this->assertStringContainsString('JitNestedHelperCoerce::i64ToTypedPtr', $gc);
    }
}
