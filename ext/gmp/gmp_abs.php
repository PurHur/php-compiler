<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_abs() — absolute value (php-src ext/gmp/gmp.c; issue #19527). */
final class gmp_abs extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_abs');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_abs');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_abs() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_abs() requires VM context in this compiler build');
        }
        $num = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_abs', 0, 'a');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::abs($num));
    }
}
