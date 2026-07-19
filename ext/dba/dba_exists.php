<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** dba_exists() — php-src ext/dba/dba.c (#4422). */
final class dba_exists extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_exists');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'dba_exists() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $key = VmDbaCore::coerceKey($frame->calledArgs[0], 'dba_exists', 0);
        $conn = VmDbaCore::requireConnection($frame->calledArgs[1], 'dba_exists');
        $ok = VmDbaCore::exists($conn, $key);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($ok): void {
                $ret->bool($ok);
            }
        );
    }
}
