<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** shmop_open() — open System V shared memory segment (php-src ext/sysvshm/shmop.c; #3344). */
final class shmop_open extends Internal
{
    public function __construct()
    {
        parent::__construct('shmop_open');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shmop_open() expects exactly 4 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        ShmopArgs::requireAvailable('shmop_open');
        $key = ShmopArgs::parseKey($frame, 'shmop_open');
        $mode = ShmopArgs::parseMode($frame, 'shmop_open');
        $permissions = ShmopArgs::parsePermissions($frame, 'shmop_open');
        $size = ShmopArgs::parseSize($frame, 'shmop_open');

        [$result, $message] = VmShmop::open($frame->vmContext, $key, $mode, $permissions, $size);
        if (false === $result) {
            $this->triggerWarning($frame, $message);
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (4 !== $argc) {
            return JitShmopOpen::emitArgumentCountError($context, $argc);
        }

        return JitShmopOpen::invoke($context, $args[0], $args[1], $args[2], $args[3]);
    }

    private function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
