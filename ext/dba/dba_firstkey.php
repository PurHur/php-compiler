<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** dba_firstkey() — php-src ext/dba/dba.c (#21167). */
final class dba_firstkey extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_firstkey');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'dba_firstkey() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmDbaCore::requireConnection($frame->calledArgs[0], 'dba_firstkey');
        $key = VmDbaCore::firstKey($conn);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($key): void {
                if (false === $key) {
                    $ret->bool(false);

                    return;
                }
                $ret->string($key);
            }
        );
    }
}
