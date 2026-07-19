<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** dba_delete() — php-src ext/dba/dba.c (#4422). */
final class dba_delete extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_delete');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'dba_delete() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $key = VmDbaCore::coerceKey($frame->calledArgs[0], 'dba_delete', 0);
        $conn = VmDbaCore::requireConnection($frame->calledArgs[1], 'dba_delete');
        $ok = VmDbaCore::delete($conn, $key);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($ok): void {
                $ret->bool($ok);
            }
        );
    }
}
