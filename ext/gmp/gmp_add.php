<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_add() — add GMP integers (php-src ext/gmp/gmp.c; issue #3341). */
final class gmp_add extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_add');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_add');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_add() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_add() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_add', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_add', 1, 'num2');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::add($left, $right));
    }
}
