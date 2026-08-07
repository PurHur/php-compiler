<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** sem_remove() — remove System V semaphore (php-src ext/sysvsem/sysvsem.c; #3704). */
final class sem_remove extends Internal
{
    public function __construct()
    {
        parent::__construct('sem_remove');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'sem_remove() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SemArgs::requireAvailable('sem_remove');
        $object = SemArgs::parseSemaphore($frame, 'sem_remove');
        $host = SemArgs::requireHost($object, 'sem_remove');

        $result = VmSem::remove($host);
        if ($result) {
            VmSem::detachObject($object);
        } else {
            $this->triggerWarning($frame, 'sem_remove() failed');
        }
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitSemRemove::emitArgumentCountError($context, $argc);
        }

        return JitSemRemove::invoke($context, $args[0]);
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
