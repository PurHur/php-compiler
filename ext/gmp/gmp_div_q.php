<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/**
 * gmp_div_q() — toward-zero quotient (php-src ext/gmp/gmp.c; issue #19527).
 * Also registered as gmp_div (@alias gmp_div_q; #28746).
 */
final class gmp_div_q extends GmpFunction
{
    public function __construct(string $name = 'gmp_div_q')
    {
        parent::__construct($name);
    }

    protected function compute(Frame $frame)
    {
        $fn = $this->getName();
        VmGmp::requireAvailable($fn);
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                $fn.'() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException($fn.'() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], $fn, 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], $fn, 1, 'num2');
        // Rounding mode arg ignored for v1 — php-src default GMP_ROUND_ZERO.

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::divQ($left, $right));
    }
}
