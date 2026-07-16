<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** gmp_import() — php-src ext/gmp/gmp.c; issue #19540. */
final class gmp_import extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_import');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_import');

        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'gmp_import() expects 1 to 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('gmp_import() requires VM context in this compiler build');
        }
        $dataVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $dataVar->type) {
            throw new \TypeError('gmp_import(): Argument #1 ($data) must be of type string');
        }
        $wordSize = 1;
        $flags = VmGmp::GMP_MSW_FIRST | VmGmp::GMP_NATIVE_ENDIAN;
        if ($argc >= 2) {
            $ws = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $ws->type) {
                throw new \TypeError('gmp_import(): Argument #2 ($word_size) must be of type int');
            }
            $wordSize = $ws->toInt();
        }
        if ($argc >= 3) {
            $fl = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $fl->type) {
                throw new \TypeError('gmp_import(): Argument #3 ($flags) must be of type int');
            }
            $flags = $fl->toInt();
        }
        return VmGmpObject::fromSignedDecimal(
            $frame->vmContext,
            VmGmp::import($dataVar->toString(), $wordSize, $flags)
        );

    }
}
