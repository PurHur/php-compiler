<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmRoundMode;

/** bcround() — arbitrary-precision rounding (php-src ext/bcmath/bcmath.c; issue #5935). */
final class bcround extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcround');
    }

    protected function compute(Frame $frame): string
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('bcround() requires one to three arguments in this compiler build');
        }

        $num = $this->requireStringArg($frame, 0, 'num');
        $precision = 0;
        if ($argc >= 2) {
            $precision = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                'bcround',
                2,
                'precision'
            );
        }

        // php-src bcmath.stub.php — RoundingMode $mode = HalfAwayFromZero (#28566).
        $mode = StdlibConstants::PHP_ROUND_HALF_UP;
        if ($argc >= 3) {
            $mode = VmRoundMode::resolveRoundingModeOnlyArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'bcround'
            );
        }

        return VmBcmath::round($num, $precision, $mode);
    }
}
