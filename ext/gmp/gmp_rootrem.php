<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;

/** gmp_rootrem() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_rootrem extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_rootrem');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_rootrem');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_rootrem() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_rootrem() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_rootrem', 0, 'a');
        $nth = VmGmp::coerceNth($frame->calledArgs[1], 'gmp_rootrem');
        [$root, $rem] = VmGmp::rootrem($a, $nth);
        $ht = new HashTable();
        $ht->addIndex(0, VmGmpObject::fromSignedDecimal($frame->vmContext, $root));
        $ht->addIndex(1, VmGmpObject::fromSignedDecimal($frame->vmContext, $rem));

        return $ht;
    }
}