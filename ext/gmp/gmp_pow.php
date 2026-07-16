<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_pow() — raise base to non-negative exponent (php-src ext/gmp/gmp.c; issue #19527). */
final class gmp_pow extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_pow');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_pow');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_pow() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_pow() requires VM context in this compiler build');
        }
        $base = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_pow', 0, 'num');
        $exponent = VmGmp::coerceExponent($frame->calledArgs[1], 'gmp_pow');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::pow($base, $exponent));
    }
}
