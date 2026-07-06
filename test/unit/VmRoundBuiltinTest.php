<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\round;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmRound;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM round() parity with VmRound::mathRound (#5171). */
final class VmRoundBuiltinTest extends TestCase
{
    public function testMathRoundHalfUpAndPrecision(): void
    {
        $this->assertSame(3.0, VmRound::mathRound(2.5, 0, StdlibConstants::PHP_ROUND_HALF_UP));
        $this->assertSame(-1.0, VmRound::mathRound(-0.5, 0, StdlibConstants::PHP_ROUND_HALF_UP));
        $this->assertSame(-2.0, VmRound::mathRound(-1.5, 0, StdlibConstants::PHP_ROUND_HALF_UP));
        $this->assertSame(1.0, VmRound::mathRound(0.5, 0, StdlibConstants::PHP_ROUND_HALF_UP));
        $this->assertSame(1.5, VmRound::mathRound(1.5, 2, StdlibConstants::PHP_ROUND_HALF_UP));
        $this->assertSame(2.0, VmRound::mathRound(2.5, 0, StdlibConstants::PHP_ROUND_HALF_DOWN));
        $this->assertSame(2.0, VmRound::mathRound(2.5, 0, StdlibConstants::PHP_ROUND_HALF_EVEN));
        $this->assertSame(4.0, VmRound::mathRound(3.5, 0, StdlibConstants::PHP_ROUND_HALF_EVEN));
        $this->assertSame(3.0, VmRound::mathRound(2.5, 0, StdlibConstants::PHP_ROUND_HALF_ODD));
        $this->assertSame(3.0, VmRound::mathRound(2.5, 0, 99));
    }

    public function testBuiltinExecuteMatchesMathRound(): void
    {
        $runtime = new Runtime();
        $fn = new round();
        $frame = $fn->getFrame($runtime->vmContext);
        $num = new VMVariable();
        $num->float(2.5);
        $prec = new VMVariable();
        $prec->int(0);
        $modeVar = new VMVariable();
        $modeVar->int(StdlibConstants::PHP_ROUND_HALF_UP);
        $frame->calledArgs = [$num, $prec, $modeVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $this->assertSame(
            VmRound::mathRound(2.5, 0, StdlibConstants::PHP_ROUND_HALF_UP),
            $frame->returnVar->resolveIndirect()->toFloat()
        );
    }

    /** @dataProvider precisionCoercionProvider */
    public function testPrecisionCoercion(float $num, mixed $precision, float $expected): void
    {
        $runtime = new Runtime();
        $fn = new round();
        $frame = $fn->getFrame($runtime->vmContext);
        $numVar = new VMVariable();
        $numVar->float($num);
        $precVar = new VMVariable();
        if (\is_float($precision)) {
            $precVar->float($precision);
        } else {
            $precVar->string((string) $precision);
        }
        $frame->calledArgs = [$numVar, $precVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $this->assertSame($expected, $frame->returnVar->resolveIndirect()->toFloat());
    }

    /** @return list<array{float, float|string, float}> */
    public static function precisionCoercionProvider(): array
    {
        return [
            [1.5, 0.9, 2.0],
            [1.5, '1', 1.5],
            [1.5, 1.9, 1.5],
        ];
    }
}
