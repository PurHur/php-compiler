<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** AssertOptionsRuntime must route through AssertOptionsJitHelper PHP, not LLVM globals (#9513, #9894, #21528). */
final class AssertOptionsRuntimeShrinkTest extends TestCase
{
    public function testAssertOptionsRuntimeUsesAssertOptionsJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertOptionsRuntime.php');
        $this->assertStringContainsString('AssertOptionsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('AssertIniRuntime::G_ASSERT_ACTIVE', $source);
        $this->assertStringNotContainsString('addGlobal($i32, \'phpc_assert_active\')', $source);
        $this->assertStringNotContainsString('malloc', $source);
        $this->assertStringNotContainsString('implementAbiBridges', $source);
        $this->assertStringNotContainsString('__phpc_assert_enabled', $source);
        $this->assertStringNotContainsString('implementStandaloneStubs', $source);
        $this->assertStringNotContainsString('aopt_standalone_stub', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
    }

    public function testAssertIniRuntimeRoutesThroughAssertOptionsJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertIniRuntime.php');
        $this->assertStringContainsString('AssertOptionsRuntime', $source);
        $this->assertStringContainsString('AssertOptionsJitHelper', $source);
        $this->assertStringNotContainsString('__phpc_assert_', $source);
        $this->assertStringNotContainsString('addGlobal', $source);
        $this->assertStringNotContainsString('addFunction', $source);
    }

    public function testVmAssertStateDelegatesToAssertOptionsJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmAssertState.php');
        $this->assertStringContainsString('AssertOptionsJitHelper', $source);
        $this->assertStringNotContainsString('private static int $zendAssertions', $source);
    }

    public function testEnsureStandaloneBodiesDelegatesToEnsureLinked(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertOptionsRuntime.php');
        $this->assertMatchesRegularExpression(
            '/function ensureStandaloneBodies\(Context \$context\): void\s*\{\s*self::ensureLinked\(\$context\);/',
            $source
        );
    }
}
