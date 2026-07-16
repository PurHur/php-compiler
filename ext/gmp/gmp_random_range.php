<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** gmp_random_range() — php-src ext/gmp/gmp.c; issue #19540. */
final class gmp_random_range extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_random_range');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_random_range');

        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_random_range() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_random_range() requires VM context in this compiler build');
        }
        $min = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_random_range', 0, 'min');
        $max = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_random_range', 1, 'max');
        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::randomRange($min, $max));

    }
}
