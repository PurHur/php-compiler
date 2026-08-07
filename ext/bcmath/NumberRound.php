<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmRoundMode;

/**
 * BcMath\Number::round(int $precision = 0, RoundingMode $mode = HalfAwayFromZero) — VM (#19582).
 *
 * php-src: ext/bcmath/bcmath.c PHP_METHOD(BcMath_Number, round).
 */
final class NumberRound extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('round');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::round()');
        $argc = \count($frame->calledArgs);
        $precision = 0;
        if ($argc >= 2) {
            $precision = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                'BcMath\\Number::round',
                1,
                'precision'
            );
        }
        // php-src bcmath.stub.php — RoundingMode $mode only (#28566).
        $mode = StdlibConstants::PHP_ROUND_HALF_UP;
        if ($argc >= 3) {
            $mode = VmRoundMode::resolveRoundingModeOnlyArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'BcMath\\Number::round',
                'mode',
                2
            );
        }
        $result = VmBcmath::round(VmBcMathNumber::valueString($receiver), $precision, $mode);
        $objectScale = $precision > 0 ? $precision : 0;
        $this->returnNumber($frame, $result, $objectScale);
    }
}
