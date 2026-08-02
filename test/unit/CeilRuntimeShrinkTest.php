<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CeilJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** ceil() JIT: always CeilJitHelper via JitVmHelperLink + phpc_ceil_kernel (#15129, #27003). */
final class CeilRuntimeShrinkTest extends TestCase
{
    public function testCeilUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/ceil.php');
        $this->assertStringContainsString('MathCeil::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('ceil')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCeil.php');
        $this->assertStringContainsString('CeilJitHelper', $bridge);
        $this->assertStringContainsString('phpc_ceil', $bridge);
        $this->assertStringContainsString('JitCeilKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testCeilJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CeilJitHelper.php');
        $this->assertStringContainsString('phpc_ceil_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function ceilArgv\(.*?\{[^}]*phpc_ceil_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function ceilArgv\(.*?\{[^}]*VmMath::ceil/s',
            $source
        );

        if (!\function_exists('phpc_ceil_kernel')) {
            $this->markTestSkipped('phpc_ceil_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::ceil(1.2),
            CeilJitHelper::ceilArgv(1.2)
        );
        $this->assertSame(
            VmMath::ceil(-1.7),
            CeilJitHelper::ceilArgv(-1.7)
        );
    }

    public function testContextAllowlistsCeilKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_ceil_kernel', $source);
        $this->assertStringContainsString('phpc_sqrt_kernel', $source);
    }

    public function testSpineBundleIncludesCeilJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CeilJitHelper.php', $spine);
        $this->assertStringContainsString('MathCeil.php', $spine);
        $this->assertStringContainsString('JitCeilKernel.php', $spine);
        $this->assertStringContainsString('phpc_ceil_kernel.php', $spine);
    }
}
