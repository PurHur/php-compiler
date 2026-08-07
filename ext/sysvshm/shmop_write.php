<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** shmop_write() — write bytes to shared memory (php-src ext/sysvshm/shmop.c; #3344). */
final class shmop_write extends Internal
{
    public function __construct()
    {
        parent::__construct('shmop_write');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shmop_write() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        ShmopArgs::requireAvailable('shmop_write');
        $object = ShmopArgs::parseShmop($frame, 'shmop_write');
        $data = ShmopArgs::parseData($frame, 'shmop_write', 1);
        $offset = ShmopArgs::parseOffset($frame, 'shmop_write', 2);

        $result = VmShmop::writeForObject($object, $data, $offset);
        if (false === $result) {
            $this->triggerWarning($frame, 'shmop_write() failed');
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            return JitShmopWrite::emitArgumentCountError($context, $argc);
        }

        return JitShmopWrite::invoke($context, $args[0], $args[1], $args[2]);
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
