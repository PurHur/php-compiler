<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FrexpJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * frexp() NestedJIT via JitVmHelperLink::ensureCompiled (#22575 / #29156 / peer #28716).
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

    public function testFrexpJitHelperInlinesNestedJitSafePeel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FrexpJitHelper.php');
        $this->assertStringContainsString('2048', $source);
        $this->assertStringContainsString('0.5', $source);
        $this->assertStringNotContainsString('\\floor(', $source);
        $this->assertStringNotContainsString('\\log(', $source);
        $this->assertStringNotContainsString('2 **', $source);
        $this->assertStringNotContainsString('** $exp', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        // Docblock may {@see} VmMath; compute body must not call it (#29156).
        $this->assertDoesNotMatchRegularExpression(
            '/function compute\(.*?\{[^}]*VmMath::frexp/s',
            $source
        );

        FrexpJitHelper::resetForTest();
        $this->assertSame(0.75, FrexpJitHelper::compute(12.0));
        $this->assertSame(4, FrexpJitHelper::exponent());

        FrexpJitHelper::resetForTest();
        $this->assertSame(0.0, FrexpJitHelper::compute(0.0));
        $this->assertSame(0, FrexpJitHelper::exponent());

        FrexpJitHelper::resetForTest();
        $this->assertSame(0.5, FrexpJitHelper::compute(1.0));
        $this->assertSame(1, FrexpJitHelper::exponent());

        FrexpJitHelper::resetForTest();
        $this->assertSame(-0.75, FrexpJitHelper::compute(-12.0));
        $this->assertSame(4, FrexpJitHelper::exponent());

        FrexpJitHelper::resetForTest();
        $this->assertSame(0.5, FrexpJitHelper::compute(0.25));
        $this->assertSame(-1, FrexpJitHelper::exponent());

        // Normals agree with VmMath (VmMath uses floor/log/2** — overflows on PHP_FLOAT_MAX).
        foreach ([0.1, 0.5, 2.0, 8.0, 1.5, 3.0, -0.5, 1e-300, 1e300, \PHP_FLOAT_MIN] as $n) {
            $exp = 0;
            $expected = VmMath::frexp($n, $exp);
            FrexpJitHelper::resetForTest();
            $this->assertSame($expected, FrexpJitHelper::compute($n), 'frac for '.$n);
            $this->assertSame($exp, FrexpJitHelper::exponent(), 'exp for '.$n);
        }

        FrexpJitHelper::resetForTest();
        $this->assertTrue(\is_nan(FrexpJitHelper::compute(\NAN)));
        $this->assertSame(0, FrexpJitHelper::exponent());

        FrexpJitHelper::resetForTest();
        $this->assertTrue(\is_infinite(FrexpJitHelper::compute(\INF)));
        $this->assertSame(0, FrexpJitHelper::exponent());

        FrexpJitHelper::resetForTest();
        $maxFrac = FrexpJitHelper::compute(\PHP_FLOAT_MAX);
        $this->assertSame(1024, FrexpJitHelper::exponent());
        $this->assertGreaterThanOrEqual(0.5, $maxFrac);
        $this->assertLessThan(1.0, $maxFrac);
    }

    public function testSpineBundleIncludesFrexpJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FrexpJitHelper.php', $spine);
        $this->assertStringContainsString('MathFrexp.php', $spine);
    }
}
