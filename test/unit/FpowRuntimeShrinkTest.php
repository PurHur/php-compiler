<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FpowJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * fpow()/pow float NestedJIT via JitVmHelperLink::ensureBridge (#28674 / peer #28642).
 */
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
        $this->assertStringContainsString('FpowJitHelper', $source);
        $this->assertStringContainsString('phpc_fpow', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('JitFpowKernel', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementKernelBody', $source);
        $this->assertStringNotContainsString('fpow_kernel_entry', $source);
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

    public function testJitFdivBoxedDoubleUsesJitNativeDoubleTag(): void
    {
        // __value__writeDouble stores JIT TYPE_NATIVE_DOUBLE (3); VM float tag (2) is BOOL (#20651).
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFdiv.php');
        $this->assertMatchesRegularExpression(
            '/\$doubleTy\s*=\s*\$i8->constInt\(\s*JITVariable::TYPE_NATIVE_DOUBLE/',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$doubleTy\s*=\s*\$i8->constInt\(\s*VmVariable::TYPE_FLOAT/',
            $source
        );
        $this->assertSame(3, \PHPCompiler\JIT\Variable::TYPE_NATIVE_DOUBLE);
        $this->assertSame(2, \PHPCompiler\VM\Variable::TYPE_FLOAT);
        $this->assertNotSame(
            \PHPCompiler\JIT\Variable::TYPE_NATIVE_DOUBLE,
            \PHPCompiler\VM\Variable::TYPE_FLOAT
        );
    }

    public function testFpowJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FpowJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringContainsString('expOf', $source);
        $this->assertStringContainsString('powByInt', $source);
        $this->assertStringContainsString('2048', $source);
        $this->assertStringNotContainsString('phpc_fpow_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function fpowArgv\(.*?\{[^}]*VmMath::fpow/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function fpowArgv\(.*?\{[^}]*\\\\pow\(/s',
            $source
        );

        $this->assertSame(VmMath::fpow(2.0, 3.0), FpowJitHelper::fpowArgv(2.0, 3.0));
        $this->assertEqualsWithDelta(VmMath::fpow(2.5, 1.5), FpowJitHelper::fpowArgv(2.5, 1.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::fpow(4.0, 0.5), FpowJitHelper::fpowArgv(4.0, 0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::fpow(2.0, -3.0), FpowJitHelper::fpowArgv(2.0, -3.0), 1e-15);
        $this->assertSame(VmMath::fpow(-2.0, 3.0), FpowJitHelper::fpowArgv(-2.0, 3.0));
        $this->assertSame(VmMath::fpow(-2.0, 4.0), FpowJitHelper::fpowArgv(-2.0, 4.0));
        $this->assertSame(1.0, FpowJitHelper::fpowArgv(10.0, 0.0));
        $this->assertSame(1.0, FpowJitHelper::fpowArgv(\NAN, 0.0));
        $this->assertSame(1.0, FpowJitHelper::fpowArgv(1.0, \NAN));
        $this->assertTrue(\is_nan(FpowJitHelper::fpowArgv(-2.0, 0.5)));
        $this->assertTrue(\is_infinite(FpowJitHelper::fpowArgv(0.0, -1.0)));
        $this->assertSame(0.0, FpowJitHelper::fpowArgv(0.0, 5.0));
        $this->assertEqualsWithDelta(VmMath::fpow(100.0, 0.5), FpowJitHelper::fpowArgv(100.0, 0.5), 1e-12);
        $this->assertEqualsWithDelta(VmMath::fpow(0.1, 3.0), FpowJitHelper::fpowArgv(0.1, 3.0), 1e-15);
    }

    public function testFpowKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitFpowKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_fpow_kernel.php');
    }

    public function testContextNoLongerAllowlistsFpowKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_fpow_kernel', $source);
        $this->assertStringNotContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesFpowHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FpowJitHelper.php', $spine);
        $this->assertStringContainsString('MathFpow.php', $spine);
        $this->assertStringNotContainsString('JitFpowKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_fpow_kernel.php', $spine);
    }
}
