<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RoundJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmRound;
use PHPUnit\Framework\TestCase;

/**
 * round() NestedJIT via JitVmHelperLink::ensureBridge (#28913 / peer Floor #27650).
 */
final class RoundRuntimeShrinkTest extends TestCase
{
    public function testRoundUsesJitHelperNotLoweringMonolith(): void
    {
        $jitRound = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRound.php');
        $this->assertStringContainsString('MathRound::invoke', $jitRound);
        $this->assertStringContainsString('tryFoldCompileTime', $jitRound);
        $this->assertStringContainsString('RoundingModeJit::compileTimeRoundMode', $jitRound);
        $this->assertStringNotContainsString('JitRoundLowering', $jitRound);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathRound.php');
        $this->assertStringContainsString('RoundJitHelper', $bridge);
        $this->assertStringContainsString('phpc_round', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);

        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitRoundLowering.php');
    }

    public function testRoundJitHelperDelegatesToVmRound(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RoundJitHelper.php');
        $this->assertStringContainsString('roundPlacesZero', $source);
        $this->assertStringContainsString('pow10abs', $source);
        $this->assertStringContainsString('1.0e+308', $source);
        $this->assertStringContainsString('26800', $source);
        $this->assertStringContainsString('27248', $source);
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
