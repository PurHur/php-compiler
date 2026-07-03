<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AcoshJitHelper;
use PHPCompiler\ext\standard\AsinhJitHelper;
use PHPCompiler\ext\standard\AtanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** asinh()/acosh()/atanh() JIT routes through JitHelper PHP not libc LLVM (#15221). */
final class InverseHyperbolicRuntimeShrinkTest extends TestCase
{
    public function testAsinhUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/asinh.php');
        $this->assertStringContainsString('MathAsinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('asinh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAsinh.php');
        $this->assertStringContainsString('AsinhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_asinh', $bridge);
    }

    public function testAcoshUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/acosh.php');
        $this->assertStringContainsString('MathAcosh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('acosh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAcosh.php');
        $this->assertStringContainsString('AcoshJitHelper', $bridge);
        $this->assertStringContainsString('phpc_acosh', $bridge);
    }

    public function testAtanhUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atanh.php');
        $this->assertStringContainsString('MathAtanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atanh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtanh.php');
        $this->assertStringContainsString('AtanhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_atanh', $bridge);
    }

    public function testJitHelpersDelegateToVmMath(): void
    {
        $this->assertSame(VmMath::asinh(1.0), AsinhJitHelper::asinhArgv(1.0));
        $this->assertSame(VmMath::acosh(2.0), AcoshJitHelper::acoshArgv(2.0));
        $this->assertSame(VmMath::atanh(0.5), AtanhJitHelper::atanhArgv(0.5));
    }

    public function testSpineBundleIncludesInverseHyperbolicJitHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AsinhJitHelper.php', $spine);
        $this->assertStringContainsString('AcoshJitHelper.php', $spine);
        $this->assertStringContainsString('AtanhJitHelper.php', $spine);
        $this->assertStringContainsString('MathAsinh.php', $spine);
        $this->assertStringContainsString('MathAcosh.php', $spine);
        $this->assertStringContainsString('MathAtanh.php', $spine);
    }
}
