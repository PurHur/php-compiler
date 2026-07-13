<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_sub() — subtract GMP integers (php-src ext/gmp/gmp.c; issue #3341). */
final class gmp_sub extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_sub');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_sub');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_sub() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_sub() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_sub', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_sub', 1, 'num2');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::sub($left, $right));
    }
}
