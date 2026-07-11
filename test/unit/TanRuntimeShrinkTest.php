<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TanJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** tan() JIT routes through TanJitHelper PHP not libc LLVM (#15088). */
final class TanRuntimeShrinkTest extends TestCase
{
    public function testTanUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/tan.php');
        $this->assertStringContainsString('MathTan::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('tan')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathTan.php');
        $this->assertStringContainsString('TanJitHelper', $bridge);
        $this->assertStringContainsString('phpc_tan', $bridge);
    }

    public function testTanJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TanJitHelper.php');
        $this->assertStringContainsString('VmMath::tan', $source);

        $this->assertSame(
            VmMath::tan(0.0),
            TanJitHelper::tanArgv(0.0)
        );
        $this->assertSame(
            VmMath::tan(\deg2rad(45.0)),
            TanJitHelper::tanArgv(\deg2rad(45.0))
        );
    }

    public function testSpineBundleIncludesTanJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('TanJitHelper.php', $spine);
        $this->assertStringContainsString('MathTan.php', $spine);
    }
}
