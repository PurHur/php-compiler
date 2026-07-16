<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** gmp_export() — php-src ext/gmp/gmp.c; issue #19540. */
final class gmp_export extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_export');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_export');

        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'gmp_export() expects 1 to 3 arguments, '.$argc.' given'
            );
        }
        $num = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_export', 0, 'num');
        $wordSize = 1;
        $flags = VmGmp::GMP_MSW_FIRST | VmGmp::GMP_NATIVE_ENDIAN;
        if ($argc >= 2) {
            $ws = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $ws->type) {
                throw new \TypeError('gmp_export(): Argument #2 ($word_size) must be of type int');
            }
            $wordSize = $ws->toInt();
        }
        if ($argc >= 3) {
            $fl = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $fl->type) {
                throw new \TypeError('gmp_export(): Argument #3 ($flags) must be of type int');
            }
            $flags = $fl->toInt();
        }
        return VmGmp::export($num, $wordSize, $flags);

    }
}
