<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SqrtJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** sqrt() JIT routes through SqrtJitHelper PHP not libc LLVM (#15115). */
final class SqrtRuntimeShrinkTest extends TestCase
{
    public function testSqrtUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sqrt.php');
        $this->assertStringContainsString('MathSqrt::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sqrt')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSqrt.php');
        $this->assertStringContainsString('SqrtJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sqrt', $bridge);
    }

    public function testSqrtJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SqrtJitHelper.php');
        $this->assertStringContainsString('VmMath::sqrt', $source);

        $this->assertSame(
            VmMath::sqrt(9.0),
            SqrtJitHelper::sqrtArgv(9.0)
        );
        $this->assertSame(
            VmMath::sqrt(2.0),
            SqrtJitHelper::sqrtArgv(2.0)
        );
    }

    public function testSpineBundleIncludesSqrtJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SqrtJitHelper.php', $spine);
        $this->assertStringContainsString('MathSqrt.php', $spine);
    }
}
