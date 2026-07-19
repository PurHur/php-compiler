<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** dba_replace() — php-src ext/dba/dba.c (#4422). */
final class dba_replace extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_replace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'dba_replace() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $key = VmDbaCore::coerceKey($frame->calledArgs[0], 'dba_replace', 0);
        $value = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'dba_replace', 1, 'value');
        $conn = VmDbaCore::requireConnection($frame->calledArgs[2], 'dba_replace');
        $ok = VmDbaCore::replace($conn, $key, $value);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($ok): void {
                $ret->bool($ok);
            }
        );
    }
}
