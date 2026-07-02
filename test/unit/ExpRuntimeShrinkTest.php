<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExpJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** exp() JIT routes through ExpJitHelper PHP not libc LLVM (#15116). */
final class ExpRuntimeShrinkTest extends TestCase
{
    public function testExpUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/exp.php');
        $this->assertStringContainsString('MathExp::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('exp')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathExp.php');
        $this->assertStringContainsString('ExpJitHelper', $bridge);
        $this->assertStringContainsString('phpc_exp', $bridge);
    }

    public function testExpJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ExpJitHelper.php');
        $this->assertStringContainsString('VmMath::exp', $source);

        $this->assertSame(
            VmMath::exp(0.0),
            ExpJitHelper::expArgv(0.0)
        );
        $this->assertSame(
            VmMath::exp(1.0),
            ExpJitHelper::expArgv(1.0)
        );
    }

    public function testSpineBundleIncludesExpJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ExpJitHelper.php', $spine);
        $this->assertStringContainsString('MathExp.php', $spine);
    }
}
