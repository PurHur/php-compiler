<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ModfJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * Internal modf stays PHP-in-PHP via ModfJitHelper (#15200 / #22519).
 * Userland modf() was a phantom vs php-src and was unregistered (#25359).
 */
final class ModfRuntimeShrinkTest extends TestCase
{
    public function testMathModfBridgeUsesJitHelperNotLibcLookup(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathModf.php');
        $this->assertStringContainsString('ModfJitHelper', $bridge);
        $this->assertStringContainsString('phpc_modf', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringNotContainsString("lookupFunction('modf')", $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/modf.php');
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
        $this->assertStringNotContainsString('ext/standard/modf.php', $spine);
    }
}
