<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** sem_release() — release System V semaphore (php-src ext/sysvsem/sysvsem.c; #3704). */
final class sem_release extends Internal
{
    public function __construct()
    {
        parent::__construct('sem_release');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'sem_release() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SemArgs::requireAvailable('sem_release');
        $object = SemArgs::parseSemaphore($frame, 'sem_release');
        $host = SemArgs::requireHost($object, 'sem_release');

        $result = VmSem::release($host);
        if (!$result) {
            $this->triggerWarning($frame, 'sem_release() failed');
        }
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitSemRelease::emitArgumentCountError($context, $argc);
        }

        return JitSemRelease::invoke($context, $args[0]);
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
