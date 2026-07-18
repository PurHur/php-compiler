<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_clrbit() — mutates GMP in place (php-src ext/gmp/gmp.c; issue #20394). */
final class gmp_clrbit extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_clrbit');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_clrbit');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_clrbit() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $obj = VmGmpObject::requireGmp($frame->calledArgs[0], 'gmp_clrbit', 0, 'num');
        $index = VmGmp::coerceBitIndex($frame->calledArgs[1], 'gmp_clrbit', 1, 'index');
        $next = VmGmp::withBit(VmGmp::objectToSignedDecimal($obj), $index, false);
        VmGmp::initObject($obj, $next);

        return true;
    }
}