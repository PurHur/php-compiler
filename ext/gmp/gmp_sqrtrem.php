<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_sqrtrem() — php-src ext/gmp/gmp.c; issue #19539. */
final class gmp_sqrtrem extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_sqrtrem');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_sqrtrem');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_sqrtrem() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_sqrtrem() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_sqrtrem', 0, 'a');
        [$root, $rem] = VmGmp::sqrtrem($a);
        $ht = new \PHPCompiler\VM\HashTable();
        $ht->addIndex(0, VmGmpObject::fromSignedDecimal($frame->vmContext, $root));
        $ht->addIndex(1, VmGmpObject::fromSignedDecimal($frame->vmContext, $rem));

        return $ht;
    }
}
