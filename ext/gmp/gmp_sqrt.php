<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_sqrt() — php-src ext/gmp/gmp.c; issue #19539. */
final class gmp_sqrt extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_sqrt');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_sqrt');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_sqrt() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_sqrt() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_sqrt', 0, 'a');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::sqrt($a));
    }
}
