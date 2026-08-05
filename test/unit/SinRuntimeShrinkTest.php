<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SinJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** sin() JIT: always SinJitHelper via JitVmHelperLink + phpc_sin_kernel (#15086, #27048). */
final class SinRuntimeShrinkTest extends TestCase
{
    public function testSinUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sin.php');
        $this->assertStringContainsString('MathSin::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sin')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSin.php');
        $this->assertStringContainsString('SinJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sin', $bridge);
        $this->assertStringContainsString('JitSinKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testSinJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SinJitHelper.php');
        $this->assertStringContainsString('phpc_sin_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function sinArgv\(.*?\{[^}]*phpc_sin_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function sinArgv\(.*?\{[^}]*VmMath::sin/s',
            $source
        );

        if (!\function_exists('phpc_sin_kernel')) {
            $this->markTestSkipped('phpc_sin_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::sin(0.0),
            SinJitHelper::sinArgv(0.0)
        );
        $this->assertSame(
            VmMath::sin(\deg2rad(90.0)),
            SinJitHelper::sinArgv(\deg2rad(90.0))
        );
    }

    public function testContextAllowlistsSinKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_sin_kernel', $source);
        $this->assertStringContainsString('phpc_hypot_kernel', $source);
    }

    public function testSpineBundleIncludesSinJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SinJitHelper.php', $spine);
        $this->assertStringContainsString('MathSin.php', $spine);
        $this->assertStringContainsString('JitSinKernel.php', $spine);
        $this->assertStringContainsString('phpc_sin_kernel.php', $spine);
    }
}
