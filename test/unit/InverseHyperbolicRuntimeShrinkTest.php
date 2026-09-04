<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AcoshJitHelper;
use PHPCompiler\ext\standard\AsinhJitHelper;
use PHPCompiler\ext\standard\AtanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * asinh()/acosh()/atanh() AOT uses libm asinh(3)/acosh(3)/atanh(3) (#36386);
 * *JitHelper remain NestedJIT-safe reference (peer MathSinh / SinhJitHelper).
 * LLVM 9 has no llvm.asinh.f64 / llvm.acosh.f64 / llvm.atanh.f64.
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(asinh|acosh|atanh).
 */
final class InverseHyperbolicRuntimeShrinkTest extends TestCase
{
    public function testAsinhUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/asinh.php');
        $this->assertStringContainsString('MathAsinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('asinh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAsinh.php');
        $this->assertStringContainsString("LIBC_ASINH = 'asinh'", $bridge);
        $this->assertStringContainsString('phpc_asinh', $bridge);
        $this->assertStringContainsString('asinh_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('AsinhJitHelper', $bridge);
        $this->assertStringNotContainsString('JitAsinhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.asinh', $bridge);
    }

    public function testAcoshUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/acosh.php');
        $this->assertStringContainsString('MathAcosh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('acosh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAcosh.php');
        $this->assertStringContainsString("LIBC_ACOSH = 'acosh'", $bridge);
        $this->assertStringContainsString('phpc_acosh', $bridge);
        $this->assertStringContainsString('acosh_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('AcoshJitHelper', $bridge);
        $this->assertStringNotContainsString('JitAcoshKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.acosh', $bridge);
    }

    public function testAtanhUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atanh.php');
        $this->assertStringContainsString('MathAtanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atanh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtanh.php');
        $this->assertStringContainsString("LIBC_ATANH = 'atanh'", $bridge);
        $this->assertStringContainsString('phpc_atanh', $bridge);
        $this->assertStringContainsString('atanh_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('AtanhJitHelper', $bridge);
        $this->assertStringNotContainsString('JitAtanhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.atanh', $bridge);
    }

    public function testAsinhJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AsinhJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringNotContainsString('phpc_asinh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function asinhArgv\(.*?\{[^}]*VmMath::asinh/s',
            $source
        );

        $this->assertSame(VmMath::asinh(0.0), AsinhJitHelper::asinhArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::asinh(1.0), AsinhJitHelper::asinhArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asinh(2.0), AsinhJitHelper::asinhArgv(2.0), 1e-15);
    }

    public function testAcoshJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AcoshJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringNotContainsString('phpc_acosh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function acoshArgv\(.*?\{[^}]*VmMath::acosh/s',
            $source
        );

        $this->assertSame(VmMath::acosh(1.0), AcoshJitHelper::acoshArgv(1.0));
        $this->assertEqualsWithDelta(VmMath::acosh(2.0), AcoshJitHelper::acoshArgv(2.0), 1e-15);
    }

    public function testAtanhJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AtanhJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringNotContainsString('phpc_atanh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function atanhArgv\(.*?\{[^}]*VmMath::atanh/s',
            $source
        );

        $this->assertSame(VmMath::atanh(0.0), AtanhJitHelper::atanhArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::atanh(0.5), AtanhJitHelper::atanhArgv(0.5), 1e-15);
    }

    public function testSpineBundleIncludesInverseHyperbolicJitHelpersWithoutKernels(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        foreach ([
            'AsinhJitHelper.php', 'AcoshJitHelper.php', 'AtanhJitHelper.php',
            'MathAsinh.php', 'MathAcosh.php', 'MathAtanh.php',
        ] as $f) {
            $this->assertStringContainsString($f, $spine);
        }
        $this->assertStringNotContainsString('JitAsinhKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_asinh_kernel.php', $spine);
        $this->assertStringNotContainsString('JitAcoshKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_acosh_kernel.php', $spine);
        $this->assertStringNotContainsString('JitAtanhKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_atanh_kernel.php', $spine);
    }
}
