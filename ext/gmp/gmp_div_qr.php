<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;

/** gmp_div_qr() — toward-zero quotient + remainder array (php-src ext/gmp/gmp.c; issue #19527). */
final class gmp_div_qr extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_div_qr');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_div_qr');
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'gmp_div_qr() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_div_qr() requires VM context in this compiler build');
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_div_qr', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_div_qr', 1, 'num2');
        [$q, $r] = VmGmp::divQr($left, $right);
        $ht = new HashTable();
        $ht->addIndex(0, VmGmpObject::fromSignedDecimal($frame->vmContext, $q));
        $ht->addIndex(1, VmGmpObject::fromSignedDecimal($frame->vmContext, $r));

        return $ht;
    }
}
