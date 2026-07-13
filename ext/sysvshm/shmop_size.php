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
        $host = ShmopArgs::requireHost($object, 'shmop_size');

        $result = VmShmop::size($host);
        if (false === $result) {
            $this->triggerWarning($frame, 'shmop_size() failed');
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shmop_size() is not supported for JIT/AOT in this compiler build (issue #3344)'
        );
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
