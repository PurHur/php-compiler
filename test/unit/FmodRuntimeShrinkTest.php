<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FmodJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** fmod() JIT routes through FmodJitHelper PHP not libc LLVM (#15072). */
final class FmodRuntimeShrinkTest extends TestCase
{
    public function testFmodUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/fmod.php');
        $this->assertStringContainsString('MathFmod::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('fmod')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathFmod.php');
        $this->assertStringContainsString('FmodJitHelper', $bridge);
        $this->assertStringContainsString('phpc_fmod', $bridge);
    }

    public function testFmodJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FmodJitHelper.php');
        $this->assertStringContainsString('VmMath::fmod', $source);

        $this->assertSame(
            VmMath::fmod(5.5, 2.0),
            FmodJitHelper::fmodArgv(5.5, 2.0)
        );
        $this->assertSame(
            VmMath::fmod(-1.5, 1.2),
            FmodJitHelper::fmodArgv(-1.5, 1.2)
        );
    }

    public function testSpineBundleIncludesFmodJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FmodJitHelper.php', $spine);
        $this->assertStringContainsString('MathFmod.php', $spine);
    }
}
