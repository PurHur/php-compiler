<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_com() — php-src ext/gmp/gmp.c; issue #19539. */
final class gmp_com extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_com');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_com');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_com() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_com() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_com', 0, 'num');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::com($a));
    }
}
