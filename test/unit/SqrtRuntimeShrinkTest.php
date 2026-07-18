<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SqrtJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** sqrt() JIT: always SqrtJitHelper via JitVmHelperLink + phpc_sqrt_kernel (#15115, #20664). */
final class SqrtRuntimeShrinkTest extends TestCase
{
    public function testSqrtUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sqrt.php');
        $this->assertStringContainsString('MathSqrt::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sqrt')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSqrt.php');
        $this->assertStringContainsString('SqrtJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sqrt', $bridge);
        $this->assertStringContainsString('JitSqrtKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testSqrtJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SqrtJitHelper.php');
        $this->assertStringContainsString('phpc_sqrt_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function sqrtArgv\(.*?\{[^}]*phpc_sqrt_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function sqrtArgv\(.*?\{[^}]*VmMath::sqrt/s',
            $source
        );

        if (!\function_exists('phpc_sqrt_kernel')) {
            $this->markTestSkipped('phpc_sqrt_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::sqrt(9.0),
            SqrtJitHelper::sqrtArgv(9.0)
        );
        $this->assertSame(
            VmMath::sqrt(2.0),
            SqrtJitHelper::sqrtArgv(2.0)
        );
    }

    public function testSpineBundleIncludesSqrtJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SqrtJitHelper.php', $spine);
        $this->assertStringContainsString('MathSqrt.php', $spine);
        $this->assertStringContainsString('JitSqrtKernel.php', $spine);
        $this->assertStringContainsString('phpc_sqrt_kernel.php', $spine);
    }
}
