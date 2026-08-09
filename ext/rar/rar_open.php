<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** rar_open() — thin wrapper around RarArchive::open (PECL rar rararch.c; #27878). */
final class rar_open extends RarProceduralFunction
{
    public function __construct()
    {
        parent::__construct('rar_open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'rar_open() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('rar_open() requires an active VM context');
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'rar_open', 0, 'filename');
        $password = null;
        if ($argc >= 2 && Variable::TYPE_NULL !== $frame->calledArgs[1]->resolveIndirect()->type) {
            $password = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'rar_open', 1, 'password');
        }
        // Optional volume-callback arg accepted for signature parity; ignored in v1.
        try {
            $object = VmRar::open($frame->vmContext, $filename, $password);
        } catch (\RarException) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($object);
    }
}
