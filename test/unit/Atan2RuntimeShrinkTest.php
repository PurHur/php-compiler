<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Atan2JitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** atan2() JIT routes through Atan2JitHelper PHP not libc LLVM (#15102). */
final class Atan2RuntimeShrinkTest extends TestCase
{
    public function testAtan2UsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atan2.php');
        $this->assertStringContainsString('MathAtan2::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atan2')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtan2.php');
        $this->assertStringContainsString('Atan2JitHelper', $bridge);
        $this->assertStringContainsString('phpc_atan2', $bridge);
    }

    public function testAtan2JitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Atan2JitHelper.php');
        $this->assertStringContainsString('VmMath::atan2', $source);

        $this->assertSame(
            VmMath::atan2(1.0, 1.0),
            Atan2JitHelper::atan2Argv(1.0, 1.0)
        );
        $this->assertSame(
            VmMath::atan2(0.0, 1.0),
            Atan2JitHelper::atan2Argv(0.0, 1.0)
        );
    }

    public function testSpineBundleIncludesAtan2JitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Atan2JitHelper.php', $spine);
        $this->assertStringContainsString('MathAtan2.php', $spine);
    }
}
