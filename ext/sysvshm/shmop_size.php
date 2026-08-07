<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** shmop_size() — shared memory segment size (php-src ext/sysvshm/shmop.c; #3344). */
final class shmop_size extends Internal
{
    public function __construct()
    {
        parent::__construct('shmop_size');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shmop_size() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        ShmopArgs::requireAvailable('shmop_size');
        $object = ShmopArgs::parseShmop($frame, 'shmop_size');

        $result = VmShmop::sizeForObject($object);
        if (false === $result) {
            $this->triggerWarning($frame, 'shmop_size() failed');
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitShmopSize::emitArgumentCountError($context, $argc);
        }

        return JitShmopSize::invoke($context, $args[0]);
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
