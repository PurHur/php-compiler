<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_strval() — format GMP integer as string (php-src ext/gmp/gmp.c; issue #3341). */
final class gmp_strval extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_strval');
    }

    protected function compute(Frame $frame): string
    {
        VmGmp::requireAvailable('gmp_strval');
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'gmp_strval() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        $object = VmGmpObject::requireGmp($frame->calledArgs[0], 'gmp_strval', 0, 'num');
        $base = 10;
        if (2 === $argc) {
            $baseVar = $frame->calledArgs[1]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $baseVar->type) {
                throw new \TypeError('gmp_strval(): Argument #2 ($base) must be of type int');
            }
            $base = $baseVar->toInt();
        }

        return VmGmp::strval(VmGmp::objectToSignedDecimal($object), $base);
    }
}
