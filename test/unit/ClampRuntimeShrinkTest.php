<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ClampJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * clamp NestedJIT via JitVmHelperLink (#17336 / #29730 / peer #29578).
 */
final class ClampRuntimeShrinkTest extends TestCase
{
    public function testMathClampBridgeUsesJitHelperNotVmMathDispatch(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathClamp.php');
        $this->assertStringContainsString('ClampJitHelper', $bridge);
        $this->assertStringContainsString('phpc_clamp', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('NestedJIT-safe', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testClampJitHelperInlinesNestedJitSafePeel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ClampJitHelper.php');
        $this->assertStringContainsString('private static function cmp', $source);
        $this->assertStringContainsString('$minF !== $minF', $source);
        $this->assertStringNotContainsString('VmMath::', $source);
        $this->assertStringNotContainsString('\\is_nan(', $source);
        $this->assertStringNotContainsString('is_nan(', $source);
        $this->assertStringNotContainsString('spaceshipCompare', $source);

        $this->assertSame(3, $this->runHelper(5, 1, 3));
        $this->assertSame(1, $this->runHelper(0, 1, 3));
        $this->assertSame(2, $this->runHelper(2, 1, 3));
        $this->assertSame(1.5, $this->runHelper(1.5, 1.0, 3.0));
        $this->assertSame(1.0, $this->runHelper(0.5, 1.0, 3.0));
        $this->assertSame(3.0, $this->runHelper(4.0, 1.0, 3.0));
        $this->assertSame(-1, $this->runHelper(-5, -1, 1));

        foreach (
            [
                [5, 1, 3],
                [0, 1, 3],
                [2, 1, 3],
                [1.5, 1.0, 3.0],
                [0.5, 1.0, 3.0],
                [4.0, 1.0, 3.0],
                [-5, -1, 1],
                [2, 2, 2],
                [1.0, 1, 3],
                [5, 1.0, 3.0],
            ] as [$v, $lo, $hi]
        ) {
            $this->assertSame(
                $this->runVmMath($v, $lo, $hi),
                $this->runHelper($v, $lo, $hi),
                'clamp('.$v.', '.$lo.', '.$hi.')'
            );
        }

        try {
            $this->runHelper(1, 3, 2);
            $this->fail('expected ValueError for min > max');
        } catch (\ValueError $e) {
            $this->assertStringContainsString('must be smaller than or equal', $e->getMessage());
        }

        try {
            $this->runHelper(1.0, NAN, 2.0);
            $this->fail('expected ValueError for NAN min');
        } catch (\ValueError $e) {
            $this->assertStringContainsString('Argument #2 ($min) must not be NAN', $e->getMessage());
        }

        try {
            $this->runHelper(1.0, 0.0, NAN);
            $this->fail('expected ValueError for NAN max');
        } catch (\ValueError $e) {
            $this->assertStringContainsString('Argument #3 ($max) must not be NAN', $e->getMessage());
        }
    }

    public function testSpineBundleIncludesClampJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ClampJitHelper.php', $spine);
        $this->assertStringContainsString('MathClamp.php', $spine);
    }

    private function runHelper(int|float $value, int|float $min, int|float $max): int|float
    {
        $out = ClampJitHelper::clampArgv(
            $this->box($value),
            $this->box($min),
            $this->box($max)
        );
        $ret = $out->resolveIndirect();

        return Variable::TYPE_INTEGER === $ret->type ? $ret->toInt() : $ret->toFloat();
    }

    private function runVmMath(int|float $value, int|float $min, int|float $max): int|float
    {
        $out = new Variable();
        VmMath::clamp($this->box($value), $this->box($min), $this->box($max), $out);
        $ret = $out->resolveIndirect();

        return Variable::TYPE_INTEGER === $ret->type ? $ret->toInt() : $ret->toFloat();
    }

    private function box(int|float $scalar): Variable
    {
        $arg = new Variable();
        if (\is_int($scalar)) {
            $arg->int($scalar);
        } else {
            $arg->float($scalar);
        }

        return $arg;
    }
}
