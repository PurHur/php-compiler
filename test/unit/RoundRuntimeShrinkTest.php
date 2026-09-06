<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RoundJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmRound;
use PHPUnit\Framework\TestCase;

/**
 * round() places=0 modes AOT use LLVM f64 ops (#36386);
 * places≠0 + directed modes scale via LLVM (JitRound); RoundJitHelper remains
 * for runtime-unknown places (peer MathFloor / FloorJitHelper).
 *
 * php-src: ext/standard/math.c _php_math_round / PHP_FUNCTION(round).
 */
final class RoundRuntimeShrinkTest extends TestCase
{
    public function testRoundPlacesZeroDirectedModesUseLlvmIntrinsics(): void
    {
        $jitRound = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRound.php');
        $this->assertStringContainsString('MathRound::invokeHalfUpPlacesZero', $jitRound);
        $this->assertStringContainsString('invokeHalfDownPlacesZero', $jitRound);
        $this->assertStringContainsString('invokeHalfEvenPlacesZero', $jitRound);
        $this->assertStringContainsString('invokeHalfOddPlacesZero', $jitRound);
        $this->assertStringContainsString('invokeCeilingPlacesZero', $jitRound);
        $this->assertStringContainsString('invokeFloorPlacesZero', $jitRound);
        $this->assertStringContainsString('invokeTowardZeroPlacesZero', $jitRound);
        $this->assertStringContainsString('invokeAwayFromZeroPlacesZero', $jitRound);
        $this->assertStringContainsString('tryInvokePlacesZeroIntrinsic', $jitRound);
        $this->assertStringContainsString('tryLowerRuntimeRoundScaledIntrinsic', $jitRound);
        $this->assertStringContainsString('MathRound::invoke', $jitRound);
        $this->assertStringContainsString('tryFoldCompileTime', $jitRound);
        $this->assertStringContainsString('RoundingModeJit::compileTimeRoundMode', $jitRound);
        $this->assertStringNotContainsString('JitRoundLowering', $jitRound);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathRound.php');
        $this->assertStringContainsString('llvm.round.f64', $bridge);
        $this->assertStringContainsString('llvm.trunc.f64', $bridge);
        $this->assertStringContainsString('invokeHalfUpPlacesZero', $bridge);
        $this->assertStringContainsString('invokeHalfDownPlacesZero', $bridge);
        $this->assertStringContainsString('invokeHalfEvenPlacesZero', $bridge);
        $this->assertStringContainsString('invokeHalfOddPlacesZero', $bridge);
        $this->assertStringContainsString('invokeCeilingPlacesZero', $bridge);
        $this->assertStringContainsString('invokeFloorPlacesZero', $bridge);
        $this->assertStringContainsString('invokeTowardZeroPlacesZero', $bridge);
        $this->assertStringContainsString('invokeAwayFromZeroPlacesZero', $bridge);
        $this->assertStringContainsString('MathCeil::invoke', $bridge);
        $this->assertStringContainsString('MathFloor::invoke', $bridge);
        $this->assertStringContainsString('MathAbs::invokeDouble', $bridge);
        $this->assertStringContainsString('RoundJitHelper', $bridge);
        $this->assertStringContainsString('phpc_round', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);

        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitRoundLowering.php');
    }

    public function testRoundJitHelperKeepsNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RoundJitHelper.php');
        $this->assertStringContainsString('roundPlacesZero', $source);
        $this->assertStringContainsString('pow10abs', $source);
        $this->assertStringContainsString('1.0e+308', $source);
        $this->assertStringContainsString('26800', $source);
        $this->assertStringContainsString('27248', $source);
        $this->assertStringContainsString('llvm.round.f64', $source);
        $this->assertStringContainsString('llvm.ceil.f64', $source);
        $this->assertStringContainsString('llvm.trunc.f64', $source);
        $this->assertEqualsWithDelta(
            3.14159,
            RoundJitHelper::roundArgv(3.1415926535898, 5, StdlibConstants::PHP_ROUND_HALF_UP),
            1e-12
        );

        $this->assertSame(
            VmRound::mathRound(1.5, 0, StdlibConstants::PHP_ROUND_HALF_UP),
            RoundJitHelper::roundArgv(1.5, 0, StdlibConstants::PHP_ROUND_HALF_UP)
        );
        $this->assertSame(
            VmRound::mathRound(2.675, 2, StdlibConstants::PHP_ROUND_HALF_UP),
            RoundJitHelper::roundArgv(2.675, 2, StdlibConstants::PHP_ROUND_HALF_UP)
        );
        $this->assertSame(-1.0, VmRound::mathRound(-0.5, 0, StdlibConstants::PHP_ROUND_HALF_UP));
        $this->assertSame(-1.0, RoundJitHelper::roundArgv(-0.5, 0, StdlibConstants::PHP_ROUND_HALF_UP));

        // Directed / half modes that map to LLVM ops — helper stays SSOT for NestedJIT.
        foreach (
            [
                [1.5, StdlibConstants::PHP_ROUND_HALF_DOWN, 1.0],
                [-1.5, StdlibConstants::PHP_ROUND_HALF_DOWN, -1.0],
                [1.5, StdlibConstants::PHP_ROUND_HALF_EVEN, 2.0],
                [2.5, StdlibConstants::PHP_ROUND_HALF_EVEN, 2.0],
                [1.5, StdlibConstants::PHP_ROUND_HALF_ODD, 1.0],
                [2.5, StdlibConstants::PHP_ROUND_HALF_ODD, 3.0],
                [1.1, StdlibConstants::PHP_ROUND_CEILING, 2.0],
                [-1.1, StdlibConstants::PHP_ROUND_CEILING, -1.0],
                [1.1, StdlibConstants::PHP_ROUND_FLOOR, 1.0],
                [-1.1, StdlibConstants::PHP_ROUND_FLOOR, -2.0],
                [1.9, StdlibConstants::PHP_ROUND_TOWARD_ZERO, 1.0],
                [-1.9, StdlibConstants::PHP_ROUND_TOWARD_ZERO, -1.0],
                [1.1, StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO, 2.0],
                [-1.1, StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO, -2.0],
                [0.5, StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO, 1.0],
            ] as [$n, $mode, $expected]
        ) {
            $this->assertSame(
                $expected,
                RoundJitHelper::roundArgv($n, 0, $mode),
                'helper round('.$n.', 0, '.$mode.')'
            );
            $this->assertSame(
                $expected,
                VmRound::mathRound($n, 0, $mode),
                'VmRound round('.$n.', 0, '.$mode.')'
            );
        }
    }

    public function testSpineBundleIncludesRoundJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RoundJitHelper.php', $spine);
        $this->assertStringContainsString('RoundMath.php', $spine);
        $this->assertStringContainsString('MathRound.php', $spine);
        $this->assertStringNotContainsString('JitRoundLowering.php', $spine);
    }
}
