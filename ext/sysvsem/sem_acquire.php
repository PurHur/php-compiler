<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** sem_acquire() — acquire System V semaphore (php-src ext/sysvsem/sysvsem.c; #3704). */
final class sem_acquire extends Internal
{
    public function __construct()
    {
        parent::__construct('sem_acquire');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'sem_acquire() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SemArgs::requireAvailable('sem_acquire');
        $object = SemArgs::parseSemaphore($frame, 'sem_acquire');
        $host = SemArgs::requireHost($object, 'sem_acquire');
        $nowait = SemArgs::parseOptionalBool($frame, 1, 'sem_acquire', 'non_blocking') ?? false;

        $result = VmSem::acquire($host, $nowait);
        if (!$result) {
            $this->triggerWarning($frame, 'sem_acquire() failed');
        }
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            return JitSemAcquire::emitArgumentCountError($context, $argc);
        }

        return JitSemAcquire::invoke($context, $args);
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
