<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PowIntJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * PowIntRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#23097 / #29678 / peer #28674).
 */
final class PowIntRuntimeShrinkTest extends TestCase
{
    public function testPowIntRuntimeUsesJitHelperBridgeNotLlvmLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PowIntRuntime.php');
        $this->assertStringContainsString('PowIntJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('pow_int_loop_head', $source);
        $this->assertStringNotContainsString('mulOverflows', $source);
        $this->assertStringNotContainsString('private static function implementPowInt(', $source);
    }

    public function testPowIntJitHelperInlinesNestedJitSafePeel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PowIntJitHelper.php');
        $this->assertStringContainsString('mulOverflows', $source);
        $this->assertStringContainsString('floatPow', $source);
        $this->assertStringContainsString('intdiv', $source);
        $this->assertStringNotContainsString('VmMath::powInt', $source);
        $this->assertStringNotContainsString('$base ** $exp', $source);
        $this->assertStringNotContainsString('** $exp', $source);
        $this->assertStringNotContainsString('\\is_int(', $source);
        $this->assertStringNotContainsString('\\pow(', $source);
        $this->assertStringNotContainsString('while (', $source);

        // Normals + overflow / neg-exp edges agree with VmMath (VmMath uses **).
        foreach (
            [
                [2, 3],
                [2, 0],
                [-3, 3],
                [2, 62],
                [2, 63],
                [-2, 63],
                [2, -1],
                [-1, -1],
                [0, 0],
                [0, 5],
                [1, 100],
                [-1, 0],
                [-1, 1],
                [-1, 2],
                [10, 18],
                [10, 19],
                [-10, 18],
                [3, 39],
                [3, 40],
                [5, 27],
                [5, 28],
                [-2, 31],
                [-2, 32],
                [\PHP_INT_MAX, 1],
                [\PHP_INT_MIN, 1],
                [\PHP_INT_MIN, 0],
                [\PHP_INT_MAX, 2],
                [\PHP_INT_MIN, 2],
            ] as [$base, $exp]
        ) {
            PowIntJitHelper::resetForTest();
            $tag = PowIntJitHelper::compute($base, $exp);
            $expect = VmMath::powInt($base, $exp);
            if (\is_int($expect)) {
                $this->assertSame(0, $tag, 'tag int for '.$base.'**'.$exp);
                $this->assertSame($expect, PowIntJitHelper::resultInt(), $base.'**'.$exp);
            } else {
                $this->assertSame(1, $tag, 'tag float for '.$base.'**'.$exp);
                $got = PowIntJitHelper::resultFloat();
                if (\is_infinite($expect)) {
                    $this->assertTrue(\is_infinite($got), $base.'**'.$exp.' INF');
                    $this->assertSame($expect > 0.0, $got > 0.0, $base.'**'.$exp.' INF sign');
                } else {
                    $this->assertEqualsWithDelta($expect, $got, \abs($expect) * 1e-12 + 1e-9, $base.'**'.$exp);
                }
            }
        }

        PowIntJitHelper::resetForTest();
        $this->assertSame(0, PowIntJitHelper::compute(2, 3));
        $this->assertSame(8, PowIntJitHelper::resultInt());

        PowIntJitHelper::resetForTest();
        $this->assertSame(1, PowIntJitHelper::compute(0, -1));
        $this->assertTrue(\is_infinite(PowIntJitHelper::resultFloat()));
    }

    public function testVmMathPowIntMatchesPhpOperator(): void
    {
        $this->assertSame(8, VmMath::powInt(2, 3));
        $this->assertSame(1, VmMath::powInt(-1, 0));
        $this->assertSame(-27, VmMath::powInt(-3, 3));
        $result = VmMath::powInt(2, 62);
        $this->assertTrue(\is_int($result) || \is_float($result));
    }

    public function testJitPowStillUsesPowIntRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPow.php');
        $this->assertStringContainsString('PowIntRuntime', $source);
        $this->assertStringContainsString('__phpc_pow_int', $source);
    }
}
