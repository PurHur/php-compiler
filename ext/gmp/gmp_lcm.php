<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_lcm() — php-src ext/gmp/gmp.c; issue #19539. */
final class gmp_lcm extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_lcm');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_lcm');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_lcm() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_lcm() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_lcm', 0, 'num1');
        $b = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_lcm', 1, 'num2');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::lcm($a, $b));
    }
}
