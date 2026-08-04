<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TanJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** tan() JIT: always TanJitHelper via JitVmHelperLink + phpc_tan_kernel (#15088, #27048). */
final class TanRuntimeShrinkTest extends TestCase
{
    public function testTanUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/tan.php');
        $this->assertStringContainsString('MathTan::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('tan')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathTan.php');
        $this->assertStringContainsString('TanJitHelper', $bridge);
        $this->assertStringContainsString('phpc_tan', $bridge);
        $this->assertStringContainsString('JitTanKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testTanJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TanJitHelper.php');
        $this->assertStringContainsString('phpc_tan_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function tanArgv\(.*?\{[^}]*phpc_tan_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function tanArgv\(.*?\{[^}]*VmMath::tan/s',
            $source
        );

        if (!\function_exists('phpc_tan_kernel')) {
            $this->markTestSkipped('phpc_tan_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::tan(0.0),
            TanJitHelper::tanArgv(0.0)
        );
        $this->assertSame(
            VmMath::tan(\deg2rad(45.0)),
            TanJitHelper::tanArgv(\deg2rad(45.0))
        );
    }

    public function testContextAllowlistsTanKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_tan_kernel', $source);
        $this->assertStringContainsString('phpc_sqrt_kernel', $source);
    }

    public function testSpineBundleIncludesTanJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('TanJitHelper.php', $spine);
        $this->assertStringContainsString('MathTan.php', $spine);
        $this->assertStringContainsString('JitTanKernel.php', $spine);
        $this->assertStringContainsString('phpc_tan_kernel.php', $spine);
    }
}
