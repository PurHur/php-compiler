<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** shmop_read() — read bytes from shared memory (php-src ext/sysvshm/shmop.c; #3344). */
final class shmop_read extends Internal
{
    public function __construct()
    {
        parent::__construct('shmop_read');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shmop_read() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        ShmopArgs::requireAvailable('shmop_read');
        $object = ShmopArgs::parseShmop($frame, 'shmop_read');
        $host = ShmopArgs::requireHost($object, 'shmop_read');
        $start = ShmopArgs::parseOffset($frame, 'shmop_read', 1);
        $count = ShmopArgs::parseCount($frame, 'shmop_read', 2);

        $result = VmShmop::read($host, $start, $count);
        if (false === $result) {
            $this->triggerWarning($frame, 'shmop_read() failed');
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shmop_read() is not supported for JIT/AOT in this compiler build (issue #3344)'
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
