<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FpowJitHelper;
use PHPUnit\Framework\TestCase;

/** fpow()/pow float JIT: always FpowJitHelper via JitVmHelperLink — no thin libc fork (#15189, #20664). */
final class FpowRuntimeShrinkTest extends TestCase
{
    public function testFpowUsesJitHelperNotLibcPow(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/fpow.php');
        $this->assertStringContainsString('MathFpow::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('pow')", $builtin);

        $jitPow = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPow.php');
        $this->assertStringContainsString('MathFpow::invoke', $jitPow);
        $this->assertStringNotContainsString("lookupFunction('pow')", $jitPow);
        $this->assertStringNotContainsString('invokeLibc', $jitPow);
    }

    public function testMathFpowAlwaysUsesHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathFpow.php');
        $this->assertStringContainsString('JitFpowKernel', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('implementKernelBody', $source);
        $this->assertStringNotContainsString('fpow_kernel_entry', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('FpowJitHelper', $source);
        $this->assertStringNotContainsString('invokeLibcPow', $source);
        $this->assertStringNotContainsString("lookupFunction('pow')", $source);
        $this->assertStringNotContainsString("addFunction('pow'", $source);
        $this->assertStringNotContainsString('addFunction($abiName', $source);
    }

    public function testNestedHelperCoerceExtractsDoubleFromHelperResult(): void
    {
        $coerce = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitNestedHelperCoerce.php');
        $this->assertStringContainsString('extractDoubleFromHelperResult', $coerce);
        $this->assertStringContainsString('__value__readDouble', $coerce);
        $this->assertMatchesRegularExpression(
            '/function coerceBridgeResult\(.*?extractDoubleFromHelperResult/s',
            $coerce
        );
    }

    public function testFpowJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FpowJitHelper.php');
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringNotContainsString('\\pow(', $source);
        $this->assertStringNotContainsString('return VmMath::fpow', $source);

        if (!\function_exists('phpc_fpow_kernel')) {
            $this->markTestSkipped('phpc_fpow_kernel requires compiler runtime');
        }
        $this->assertSame(8.0, FpowJitHelper::fpowArgv(2.0, 3.0));
        $this->assertSame(\pow(2.5, 1.5), FpowJitHelper::fpowArgv(2.5, 1.5));
    }

    public function testSpineBundleIncludesFpowJitHelperAndKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FpowJitHelper.php', $spine);
        $this->assertStringContainsString('MathFpow.php', $spine);
        $this->assertStringContainsString('JitFpowKernel.php', $spine);
        $this->assertStringContainsString('phpc_fpow_kernel.php', $spine);
    }
}
