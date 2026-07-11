<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SinJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** sin() JIT routes through SinJitHelper PHP not libc LLVM (#15086). */
final class SinRuntimeShrinkTest extends TestCase
{
    public function testSinUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sin.php');
        $this->assertStringContainsString('MathSin::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sin')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSin.php');
        $this->assertStringContainsString('SinJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sin', $bridge);
    }

    public function testSinJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SinJitHelper.php');
        $this->assertStringContainsString('VmMath::sin', $source);

        $this->assertSame(
            VmMath::sin(0.0),
            SinJitHelper::sinArgv(0.0)
        );
        $this->assertSame(
            VmMath::sin(\deg2rad(90.0)),
            SinJitHelper::sinArgv(\deg2rad(90.0))
        );
    }

    public function testSpineBundleIncludesSinJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SinJitHelper.php', $spine);
        $this->assertStringContainsString('MathSin.php', $spine);
    }
}
