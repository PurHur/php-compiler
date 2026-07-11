<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CoshJitHelper;
use PHPCompiler\ext\standard\SinhJitHelper;
use PHPCompiler\ext\standard\TanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** cosh()/sinh()/tanh() JIT routes through JitHelper PHP not libc LLVM (#15156). */
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
    }

    public function testSinhUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sinh.php');
        $this->assertStringContainsString('MathSinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sinh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSinh.php');
        $this->assertStringContainsString('SinhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sinh', $bridge);
    }

    public function testTanhUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/tanh.php');
        $this->assertStringContainsString('MathTanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('tanh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathTanh.php');
        $this->assertStringContainsString('TanhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_tanh', $bridge);
    }

    public function testJitHelpersDelegateToVmMath(): void
    {
        $this->assertSame(VmMath::cosh(0.0), CoshJitHelper::coshArgv(0.0));
        $this->assertSame(VmMath::sinh(1.0), SinhJitHelper::sinhArgv(1.0));
        $this->assertSame(VmMath::tanh(2.0), TanhJitHelper::tanhArgv(2.0));
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
    }
}
