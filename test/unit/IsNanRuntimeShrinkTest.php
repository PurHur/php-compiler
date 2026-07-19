<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IsNanJitHelper;
use PHPUnit\Framework\TestCase;

/** is_nan() JIT routes through IsNanJitHelper PHP not libc isnan (#15173). */
final class IsNanRuntimeShrinkTest extends TestCase
{
    public function testIsNanUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/is_nan.php');
        $this->assertStringContainsString('MathIsNan::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('isnan')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsNan.php');
        $this->assertStringContainsString('IsNanJitHelper', $bridge);
        $this->assertStringContainsString('phpc_is_nan', $bridge);
    }

    public function testIsNanJitHelperDelegatesToPhpIsNan(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IsNanJitHelper.php');
        $this->assertStringContainsString('\\is_nan', $source);

        $this->assertSame(\is_nan(\NAN), IsNanJitHelper::isNanArgv(\NAN));
        $this->assertSame(\is_nan(1.0), IsNanJitHelper::isNanArgv(1.0));
    }

    public function testSpineBundleIncludesIsNanJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IsNanJitHelper.php', $spine);
        $this->assertStringContainsString('MathIsNan.php', $spine);
    }

    public function testFloatCompareDeclaresIsNanOnDemand(): void
    {
        $compare = (string) file_get_contents(__DIR__.'/../../lib/VM/VmFloatCompare.php');
        $this->assertStringContainsString('function lookupOrDeclareIsNan', $compare);
        $this->assertStringNotContainsString("lookupFunction('isnan')", $compare);

        $clamp = (string) file_get_contents(__DIR__.'/../../ext/standard/JitClamp.php');
        $this->assertStringContainsString('VmFloatCompare::lookupOrDeclareIsNan', $clamp);
        $this->assertStringNotContainsString("lookupFunction('isnan')", $clamp);
    }
}
