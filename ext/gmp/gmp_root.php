<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_root() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_root extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_root');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_root');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_root() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_root() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_root', 0, 'a');
        $nth = VmGmp::coerceNth($frame->calledArgs[1], 'gmp_root');

        return VmGmpObject::fromSignedDecimal($frame->vmContext, VmGmp::root($a, $nth));
    }
}