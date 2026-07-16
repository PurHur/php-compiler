<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_powm() — php-src ext/gmp/gmp.c; issue #19539. */
final class gmp_powm extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_powm');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_powm');
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_powm() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_powm() requires VM context in this compiler build');
        }
        $base = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_powm', 0, 'num');
        $exp = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_powm', 1, 'exponent');
        $mod = VmGmp::coerceGmpOperand($frame->calledArgs[2], 'gmp_powm', 2, 'modulus');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::powm($base, $exp, $mod));
    }
}
