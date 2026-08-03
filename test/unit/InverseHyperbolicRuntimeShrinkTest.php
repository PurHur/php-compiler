<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AcoshJitHelper;
use PHPCompiler\ext\standard\AsinhJitHelper;
use PHPCompiler\ext\standard\AtanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** asinh()/acosh()/atanh() JIT routes through JitHelper + libc kernels (#15221, #27058). */
final class InverseHyperbolicRuntimeShrinkTest extends TestCase
{
    public function testAsinhUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/asinh.php');
        $this->assertStringContainsString('MathAsinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('asinh')", $builtin);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAsinh.php');
        $this->assertStringContainsString('JitAsinhKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
    }

    public function testAcoshUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/acosh.php');
        $this->assertStringContainsString('MathAcosh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('acosh')", $builtin);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAcosh.php');
        $this->assertStringContainsString('JitAcoshKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
    }

    public function testAtanhUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atanh.php');
        $this->assertStringContainsString('MathAtanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atanh')", $builtin);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtanh.php');
        $this->assertStringContainsString('JitAtanhKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
    }

    public function testJitHelpersDelegateToKernel(): void
    {
        foreach (['Acosh', 'Asinh', 'Atanh'] as $name) {
            $source = (string) file_get_contents(__DIR__.'/../../ext/standard/'.$name.'JitHelper.php');
            $this->assertStringContainsString('phpc_'.strtolower($name).'_kernel', $source);
        }
        if (!\function_exists('phpc_acosh_kernel')) {
            $this->markTestSkipped('phpc_*_kernel requires compiler runtime');
        }
        $this->assertSame(VmMath::asinh(1.0), AsinhJitHelper::asinhArgv(1.0));
        $this->assertSame(VmMath::acosh(2.0), AcoshJitHelper::acoshArgv(2.0));
        $this->assertSame(VmMath::atanh(0.5), AtanhJitHelper::atanhArgv(0.5));
    }

    public function testSpineBundleIncludesInverseHyperbolicJitHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        foreach ([
            'AsinhJitHelper.php', 'AcoshJitHelper.php', 'AtanhJitHelper.php',
            'MathAsinh.php', 'MathAcosh.php', 'MathAtanh.php',
            'JitAcoshKernel.php', 'JitAsinhKernel.php', 'JitAtanhKernel.php',
            'phpc_acosh_kernel.php', 'phpc_asinh_kernel.php', 'phpc_atanh_kernel.php',
        ] as $f) {
            $this->assertStringContainsString($f, $spine);
        }
    }
}
