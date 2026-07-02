<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ModfJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** modf() JIT routes through ModfJitHelper PHP not libc LLVM (#15200). */
final class ModfRuntimeShrinkTest extends TestCase
{
    public function testModfUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/modf.php');
        $this->assertStringContainsString('MathModf::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('modf')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathModf.php');
        $this->assertStringContainsString('ModfJitHelper', $bridge);
        $this->assertStringContainsString('phpc_modf', $bridge);
    }

    public function testModfJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ModfJitHelper.php');
        $this->assertStringContainsString('VmMath::modf', $source);

        ModfJitHelper::resetForTest();
        $intPart = 0.0;
        $expectedFrac = VmMath::modf(3.75, $intPart);
        $this->assertSame($expectedFrac, ModfJitHelper::compute(3.75));
        $this->assertSame($intPart, ModfJitHelper::intPart());

        ModfJitHelper::resetForTest();
        $intPart = 0.0;
        $expectedFrac = VmMath::modf(-3.75, $intPart);
        $this->assertSame($expectedFrac, ModfJitHelper::compute(-3.75));
        $this->assertSame($intPart, ModfJitHelper::intPart());
    }

    public function testSpineBundleIncludesModfJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ModfJitHelper.php', $spine);
        $this->assertStringContainsString('MathModf.php', $spine);
    }
}
