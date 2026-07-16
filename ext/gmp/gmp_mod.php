<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_mod() — non-negative modulo (php-src ext/gmp/gmp.c; issue #19527). */
final class gmp_mod extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_mod');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_mod');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_mod() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_mod() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_mod', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_mod', 1, 'num2');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::mod($left, $right));
    }
}
