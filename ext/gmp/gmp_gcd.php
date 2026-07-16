<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_gcd() — php-src ext/gmp/gmp.c; issue #19539. */
final class gmp_gcd extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_gcd');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_gcd');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_gcd() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_gcd() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_gcd', 0, 'num1');
        $b = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_gcd', 1, 'num2');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::gcd($a, $b));
    }
}
