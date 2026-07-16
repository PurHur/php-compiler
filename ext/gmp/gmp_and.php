<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_and() — bitwise AND (php-src ext/gmp/gmp.c; issue #19527). */
final class gmp_and extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_and');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_and');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_and() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_and() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_and', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_and', 1, 'num2');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::bitwiseAnd($left, $right));
    }
}
