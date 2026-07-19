<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * dba_open() — php-src ext/dba/dba.c (#4422).
 */
final class dba_open extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'dba_open() expects between 2 and 6 arguments, %d given',
                $argc
            ));
        }
        $path = VmDbaCore::coercePathArg($frame->calledArgs[0], 'dba_open', 0, 'path');
        $mode = VmDbaCore::coerceModeArg($frame->calledArgs[1], 'dba_open');
        $handler = null;
        if ($argc >= 3) {
            $handler = VmDbaCore::coerceHandlerArg($frame->calledArgs[2], 'dba_open');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('dba_open() requires a VM context');
        }
        $result = VmDbaCore::open($path, $mode, $handler, $ctx, $frame);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($result): void {
                if (false === $result) {
                    $ret->bool(false);

                    return;
                }
                $ret->copyFrom($result);
            }
        );
    }
}
