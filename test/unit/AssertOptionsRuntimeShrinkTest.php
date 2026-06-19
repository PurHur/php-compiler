<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** AssertOptionsRuntime must route through AssertOptionsJitHelper PHP, not LLVM globals (#9513). */
final class AssertOptionsRuntimeShrinkTest extends TestCase
{
    public function testAssertOptionsRuntimeUsesAssertOptionsJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertOptionsRuntime.php');
        $this->assertStringContainsString('AssertOptionsJitHelper', $source);
        $this->assertStringNotContainsString('AssertIniRuntime::G_ASSERT_ACTIVE', $source);
        $this->assertStringNotContainsString('addGlobal($i32, \'phpc_assert_active\')', $source);
        $this->assertStringNotContainsString('malloc', $source);
    }

    public function testAssertIniRuntimeDelegatesToAssertOptionsRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertIniRuntime.php');
        $this->assertStringContainsString('__phpc_assert_enabled', $source);
        $this->assertStringNotContainsString('addGlobal', $source);
        $this->assertStringNotContainsString('G_ASSERT_ACTIVE', $source);
    }

    public function testVmAssertStateDelegatesToAssertOptionsJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmAssertState.php');
        $this->assertStringContainsString('AssertOptionsJitHelper', $source);
        $this->assertStringNotContainsString('private static int $zendAssertions', $source);
    }
}
