<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AtanJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** atan() JIT routes through AtanJitHelper PHP not libc LLVM (#15142). */
final class AtanRuntimeShrinkTest extends TestCase
{
    public function testAtanUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atan.php');
        $this->assertStringContainsString('MathAtan::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atan')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtan.php');
        $this->assertStringContainsString('AtanJitHelper', $bridge);
        $this->assertStringContainsString('phpc_atan', $bridge);
    }

    public function testAtanJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AtanJitHelper.php');
        $this->assertStringContainsString('VmMath::atan', $source);

        $this->assertSame(
            VmMath::atan(0.0),
            AtanJitHelper::atanArgv(0.0)
        );
        $this->assertSame(
            VmMath::atan(1.0),
            AtanJitHelper::atanArgv(1.0)
        );
    }

    public function testSpineBundleIncludesAtanJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AtanJitHelper.php', $spine);
        $this->assertStringContainsString('MathAtan.php', $spine);
    }
}
