<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;

/** gmp_gcdext() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_gcdext extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_gcdext');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_gcdext');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_gcdext() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_gcdext() requires VM context in this compiler build');
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_gcdext', 0, 'num1');
        $b = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_gcdext', 1, 'num2');
        [$g, $s, $t] = VmGmp::gcdext($a, $b);
        $ht = new HashTable();
        $ht->add('g', VmGmpObject::fromSignedDecimal($frame->vmContext, $g));
        $ht->add('s', VmGmpObject::fromSignedDecimal($frame->vmContext, $s));
        $ht->add('t', VmGmpObject::fromSignedDecimal($frame->vmContext, $t));

        return $ht;
    }
}