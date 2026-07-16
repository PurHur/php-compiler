<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_fact() — php-src ext/gmp/gmp.c; issue #19539. */
final class gmp_fact extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_fact');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_fact');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_fact() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_fact() requires VM context in this compiler build');
        }
        $num = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_fact', 0, 'num');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::fact($num));
    }
}
