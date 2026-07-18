<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_divexact() — exact quotient (php-src ext/gmp/gmp.c; issue #20586). */
final class gmp_divexact extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_divexact');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_divexact');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_divexact() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_divexact() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_divexact', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_divexact', 1, 'num2');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::divExact($left, $right));
    }
}
