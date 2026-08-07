<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CoshJitHelper;
use PHPCompiler\ext\standard\SinhJitHelper;
use PHPCompiler\ext\standard\TanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** cosh()/sinh()/tanh() JIT routes through JitHelper PHP (#15156, #27005). */
final class HyperbolicRuntimeShrinkTest extends TestCase
{
    public function testCoshUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/cosh.php');
        $this->assertStringContainsString('MathCosh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('cosh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCosh.php');
        $this->assertStringContainsString('CoshJitHelper', $bridge);
        $this->assertStringContainsString('phpc_cosh', $bridge);
        $this->assertStringContainsString('JitCoshKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
    }

    public function testSinhUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sinh.php');
        $this->assertStringContainsString('MathSinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sinh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSinh.php');
        $this->assertStringContainsString('SinhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sinh', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitSinhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
    }

    public function testTanhUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/tanh.php');
        $this->assertStringContainsString('MathTanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('tanh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathTanh.php');
        $this->assertStringContainsString('TanhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_tanh', $bridge);
        $this->assertStringContainsString('JitTanhKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
    }

    public function testCoshJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CoshJitHelper.php');
        $this->assertStringContainsString('phpc_cosh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function coshArgv\(.*?\{[^}]*VmMath::cosh/s',
            $source
        );

        if (!\function_exists('phpc_cosh_kernel')) {
            $this->markTestSkipped('phpc_cosh_kernel requires compiler runtime');
        }
        $this->assertSame(VmMath::cosh(0.0), CoshJitHelper::coshArgv(0.0));
        $this->assertSame(VmMath::cosh(1.0), CoshJitHelper::coshArgv(1.0));
    }

    public function testTanhJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TanhJitHelper.php');
        $this->assertStringContainsString('phpc_tanh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function tanhArgv\(.*?\{[^}]*VmMath::tanh/s',
            $source
        );

        if (!\function_exists('phpc_tanh_kernel')) {
            $this->markTestSkipped('phpc_tanh_kernel requires compiler runtime');
        }
        $this->assertSame(VmMath::tanh(0.0), TanhJitHelper::tanhArgv(0.0));
        $this->assertSame(VmMath::tanh(1.0), TanhJitHelper::tanhArgv(1.0));
        $this->assertSame(VmMath::tanh(2.0), TanhJitHelper::tanhArgv(2.0));
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
        $this->assertStringContainsString('JitCoshKernel.php', $spine);
        $this->assertStringNotContainsString('JitSinhKernel.php', $spine);
        $this->assertStringContainsString('JitTanhKernel.php', $spine);
        $this->assertStringContainsString('phpc_cosh_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_sinh_kernel.php', $spine);
        $this->assertStringContainsString('phpc_tanh_kernel.php', $spine);
        $this->assertStringNotContainsString('JitAtanhKernel.php', $spine);
        $this->assertStringNotContainsString('JitAsinhKernel.php', $spine);
        $this->assertStringNotContainsString('JitAcoshKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_acosh_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_asinh_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_atanh_kernel.php', $spine);
    }
}
