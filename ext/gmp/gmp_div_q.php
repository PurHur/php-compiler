<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_div_q() — toward-zero quotient (php-src ext/gmp/gmp.c; issue #19527). */
final class gmp_div_q extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_div_q');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_div_q');
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'gmp_div_q() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_div_q() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_div_q', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_div_q', 1, 'num2');
        // Rounding mode arg ignored for v1 — php-src default GMP_ROUND_ZERO.

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::divQ($left, $right));
    }
}
