<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_invert() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_invert extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_invert');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_invert');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_invert() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_invert() requires VM context in this compiler build');
        }
        $num = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_invert', 0, 'num1');
        $mod = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_invert', 1, 'num2');
        $inv = VmGmp::invert($num, $mod);
        if (null === $inv) {
            return false;
        }

        return VmGmpObject::fromSignedDecimal($frame->vmContext, $inv);
    }
}