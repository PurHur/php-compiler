<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AcoshJitHelper;
use PHPCompiler\ext\standard\AsinhJitHelper;
use PHPCompiler\ext\standard\AtanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * asinh()/acosh()/atanh() NestedJIT via NestedJIT-safe PHP helpers (#28377 / #28355 / #28331).
 */
final class InverseHyperbolicRuntimeShrinkTest extends TestCase
{
    public function testAsinhUsesEnsureBridgeWithoutKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/asinh.php');
        $this->assertStringContainsString('MathAsinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('asinh')", $builtin);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAsinh.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAsinhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertEqualsWithDelta(VmMath::asinh(1.0), AsinhJitHelper::asinhArgv(1.0), 1e-15);
    }

    public function testAcoshUsesEnsureBridgeWithoutKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/acosh.php');
        $this->assertStringContainsString('MathAcosh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('acosh')", $builtin);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAcosh.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAcoshKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertEqualsWithDelta(VmMath::acosh(2.0), AcoshJitHelper::acoshArgv(2.0), 1e-15);
    }

    public function testAtanhUsesEnsureBridgeWithoutKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atanh.php');
        $this->assertStringContainsString('MathAtanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atanh')", $builtin);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtanh.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAtanhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertEqualsWithDelta(VmMath::atanh(0.5), AtanhJitHelper::atanhArgv(0.5), 1e-15);
    }

    public function testInverseHyperbolicJitHelpersAreNestedJitSafePhp(): void
    {
        foreach ([
            'AsinhJitHelper.php' => 'phpc_asinh_kernel',
            'AcoshJitHelper.php' => 'phpc_acosh_kernel',
            'AtanhJitHelper.php' => 'phpc_atanh_kernel',
        ] as $file => $kernel) {
            $source = (string) file_get_contents(__DIR__.'/../../ext/standard/'.$file);
            $this->assertStringNotContainsString($kernel, $source);
            $this->assertStringContainsString('logPositive', $source);
        }
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
