<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** gmp_setbit() — mutates GMP in place (php-src ext/gmp/gmp.c; issue #20394). */
final class gmp_setbit extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_setbit');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_setbit');
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'gmp_setbit() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        $obj = VmGmpObject::requireGmp($frame->calledArgs[0], 'gmp_setbit', 0, 'num');
        $index = VmGmp::coerceBitIndex($frame->calledArgs[1], 'gmp_setbit', 1, 'index');
        $set = true;
        if (3 === $argc) {
            $flag = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $flag->type) {
                $set = $flag->toBool();
            } elseif (Variable::TYPE_INTEGER === $flag->type) {
                $set = 0 !== $flag->toInt();
            } else {
                throw new \TypeError('gmp_setbit(): Argument #3 ($value) must be of type bool');
            }
        }
        $next = VmGmp::withBit(VmGmp::objectToSignedDecimal($obj), $index, $set);
        VmGmp::initObject($obj, $next);

        return true;
    }
}