<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FrexpJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * frexp() NestedJIT via JitVmHelperLink::ensureCompiled (#22575 / peer #22519).
 */
final class FrexpRuntimeShrinkTest extends TestCase
{
    public function testFrexpUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/frexp.php');
        $this->assertStringContainsString('MathFrexp::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('frexp')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathFrexp.php');
        $this->assertStringContainsString('FrexpJitHelper', $bridge);
        $this->assertStringContainsString('phpc_frexp', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testFrexpJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FrexpJitHelper.php');
        $this->assertStringContainsString('VmMath::frexp', $source);

        FrexpJitHelper::resetForTest();
        $exp = 0;
        $expectedFrac = VmMath::frexp(12.0, $exp);
        $this->assertSame($expectedFrac, FrexpJitHelper::compute(12.0));
        $this->assertSame($exp, FrexpJitHelper::exponent());

        FrexpJitHelper::resetForTest();
        $exp = 0;
        $expectedFrac = VmMath::frexp(0.0, $exp);
        $this->assertSame($expectedFrac, FrexpJitHelper::compute(0.0));
        $this->assertSame($exp, FrexpJitHelper::exponent());
    }

    public function testSpineBundleIncludesFrexpJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FrexpJitHelper.php', $spine);
        $this->assertStringContainsString('MathFrexp.php', $spine);
    }
}
