<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_mul() — multiply GMP integers (php-src ext/gmp/gmp.c; issue #3341). */
final class gmp_mul extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_mul');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_mul');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_mul() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_mul() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_mul', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_mul', 1, 'num2');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::mul($left, $right));
    }
}
