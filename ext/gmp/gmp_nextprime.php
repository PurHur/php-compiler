<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_nextprime() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_nextprime extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_nextprime');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_nextprime');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_nextprime() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_nextprime() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_nextprime', 0, 'num');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::nextprime($a));
    }
}