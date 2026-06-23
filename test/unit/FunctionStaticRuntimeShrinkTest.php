<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Builtin\FunctionStaticRuntime;
use PHPCompiler\VM\VmFunctionStatic;
use PHPUnit\Framework\TestCase;

/** Function-static init flags centralized in FunctionStaticRuntime, not per-slot LLVM globals in helper (#10173). */
final class FunctionStaticRuntimeShrinkTest extends TestCase
{
    public function testFunctionStaticHelperUsesRuntimeAbiNotPerSlotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/FunctionStaticHelper.php');
        $this->assertStringContainsString('phpc_fn_static_is_initialized', $source);
        $this->assertStringContainsString('phpc_fn_static_mark_initialized', $source);
        $this->assertStringContainsString('FunctionStaticRuntime', (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php'));
        $this->assertStringNotContainsString('phpc_fn_static_init_', $source);
        $this->assertStringNotContainsString('addGlobal($i8', $source);
        $this->assertStringNotContainsString('$initFlags', $source);
    }

    public function testFunctionStaticRuntimeUsesModuleInitTable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FunctionStaticRuntime.php');
        $this->assertStringContainsString(FunctionStaticRuntime::INIT_TABLE_GLOBAL, $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testVmFunctionStaticSlotIdIsStable(): void
    {
        $key = 'fn:counter:$n';
        $this->assertSame(VmFunctionStatic::slotIdForKey($key), VmFunctionStatic::slotIdForKey($key));
        $this->assertNotSame(
            VmFunctionStatic::slotIdForKey($key),
            VmFunctionStatic::slotIdForKey('fn:counter:$m')
        );
    }

    public function testVmFunctionStaticInitLifecycle(): void
    {
        $initialized = [];
        $key = 'fn:test:$x';
        $this->assertFalse(VmFunctionStatic::isInitialized($key, $initialized));
        VmFunctionStatic::markInitialized($key, $initialized);
        $this->assertTrue(VmFunctionStatic::isInitialized($key, $initialized));
    }
}
