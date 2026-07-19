<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** dba_sync() — php-src ext/dba/dba.c (#21168). */
final class dba_sync extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_sync');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'dba_sync() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmDbaCore::requireConnection($frame->calledArgs[0], 'dba_sync');
        $ok = VmDbaCore::sync($conn);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($ok): void {
                $ret->bool($ok);
            }
        );
    }
}
