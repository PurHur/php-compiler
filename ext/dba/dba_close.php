<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;

/** dba_close() — php-src ext/dba/dba.c (#4422). */
final class dba_close extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'dba_close() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmDbaCore::requireConnection($frame->calledArgs[0], 'dba_close');
        VmDbaCore::close($conn);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
