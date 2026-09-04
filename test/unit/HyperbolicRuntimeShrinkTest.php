<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CoshJitHelper;
use PHPCompiler\ext\standard\SinhJitHelper;
use PHPCompiler\ext\standard\TanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * sinh()/cosh()/tanh() AOT uses libm sinh(3)/cosh(3)/tanh(3) (#36386);
 * *JitHelper remain NestedJIT-safe reference (peer MathTan / TanJitHelper).
 * LLVM 9 has no llvm.sinh.f64 / llvm.cosh.f64 / llvm.tanh.f64.
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(sinh|cosh|tanh).
 */
final class HyperbolicRuntimeShrinkTest extends TestCase
{
    public function testCoshUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/cosh.php');
        $this->assertStringContainsString('MathCosh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('cosh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCosh.php');
        $this->assertStringContainsString("LIBC_COSH = 'cosh'", $bridge);
        $this->assertStringContainsString('phpc_cosh', $bridge);
        $this->assertStringContainsString('cosh_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('CoshJitHelper', $bridge);
        $this->assertStringNotContainsString('JitCoshKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.cosh', $bridge);
    }

    public function testSinhUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sinh.php');
        $this->assertStringContainsString('MathSinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sinh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSinh.php');
        $this->assertStringContainsString("LIBC_SINH = 'sinh'", $bridge);
        $this->assertStringContainsString('phpc_sinh', $bridge);
        $this->assertStringContainsString('sinh_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('SinhJitHelper', $bridge);
        $this->assertStringNotContainsString('JitSinhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.sinh', $bridge);
    }

    public function testTanhUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/tanh.php');
        $this->assertStringContainsString('MathTanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('tanh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathTanh.php');
        $this->assertStringContainsString("LIBC_TANH = 'tanh'", $bridge);
        $this->assertStringContainsString('phpc_tanh', $bridge);
        $this->assertStringContainsString('tanh_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('TanhJitHelper', $bridge);
        $this->assertStringNotContainsString('JitTanhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.tanh', $bridge);
    }

    public function testCoshJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CoshJitHelper.php');
        $this->assertStringContainsString('expPositive', $source);
        $this->assertStringNotContainsString('phpc_cosh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function coshArgv\(.*?\{[^}]*VmMath::cosh/s',
            $source
        );

        $this->assertSame(VmMath::cosh(0.0), CoshJitHelper::coshArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::cosh(1.0), CoshJitHelper::coshArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::cosh(2.0), CoshJitHelper::coshArgv(2.0), 1e-15);
    }

    public function testTanhJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TanhJitHelper.php');
        $this->assertStringContainsString('expPositive', $source);
        $this->assertStringNotContainsString('phpc_tanh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function tanhArgv\(.*?\{[^}]*VmMath::tanh/s',
            $source
        );

        $this->assertSame(VmMath::tanh(0.0), TanhJitHelper::tanhArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::tanh(1.0), TanhJitHelper::tanhArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tanh(2.0), TanhJitHelper::tanhArgv(2.0), 1e-15);
    }

    public function testSinhJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SinhJitHelper.php');
        $this->assertStringContainsString('expPositive', $source);
        $this->assertStringNotContainsString('phpc_sinh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function sinhArgv\(.*?\{[^}]*VmMath::sinh/s',
            $source
        );

        $this->assertSame(VmMath::sinh(0.0), SinhJitHelper::sinhArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::sinh(1.0), SinhJitHelper::sinhArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::sinh(2.0), SinhJitHelper::sinhArgv(2.0), 1e-15);
    }

    public function testSpineBundleIncludesHyperbolicJitHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CoshJitHelper.php', $spine);
        $this->assertStringContainsString('SinhJitHelper.php', $spine);
        $this->assertStringContainsString('TanhJitHelper.php', $spine);
        $this->assertStringContainsString('MathCosh.php', $spine);
        $this->assertStringContainsString('MathSinh.php', $spine);
        $this->assertStringContainsString('MathTanh.php', $spine);
        $this->assertStringNotContainsString('JitCoshKernel.php', $spine);
        $this->assertStringNotContainsString('JitSinhKernel.php', $spine);
        $this->assertStringNotContainsString('JitTanhKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_cosh_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_sinh_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_tanh_kernel.php', $spine);
        $this->assertStringNotContainsString('JitAtanhKernel.php', $spine);
        $this->assertStringNotContainsString('JitAsinhKernel.php', $spine);
        $this->assertStringNotContainsString('JitAcoshKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_acosh_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_asinh_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_atanh_kernel.php', $spine);
    }
}
