<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CeilJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** ceil() JIT routes through CeilJitHelper PHP not libc LLVM (#15129). */
final class CeilRuntimeShrinkTest extends TestCase
{
    public function testCeilUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/ceil.php');
        $this->assertStringContainsString('MathCeil::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('ceil')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCeil.php');
        $this->assertStringContainsString('CeilJitHelper', $bridge);
        $this->assertStringContainsString('phpc_ceil', $bridge);
    }

    public function testCeilJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CeilJitHelper.php');
        $this->assertStringContainsString('VmMath::ceil', $source);

        $this->assertSame(
            VmMath::ceil(1.2),
            CeilJitHelper::ceilArgv(1.2)
        );
        $this->assertSame(
            VmMath::ceil(-1.7),
            CeilJitHelper::ceilArgv(-1.7)
        );
    }

    public function testSpineBundleIncludesCeilJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CeilJitHelper.php', $spine);
        $this->assertStringContainsString('MathCeil.php', $spine);
    }
}
