<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** sem_get() — get or create System V semaphore (php-src ext/sysvsem/sysvsem.c; #3704). */
final class sem_get extends Internal
{
    public function __construct()
    {
        parent::__construct('sem_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(
                'sem_get() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SemArgs::requireAvailable('sem_get');
        $key = SemArgs::parseKey($frame, 'sem_get');
        $maxAcquire = SemArgs::parseOptionalInt($frame, 1, 'sem_get', 'max_acquire');
        $perm = SemArgs::parseOptionalInt($frame, 2, 'sem_get', 'permissions');
        // php-src stub: bool $auto_release = true (Z_PARAM_BOOL coerces int; #19515)
        $autoRelease = SemArgs::parseOptionalBoolCoerce($frame, 3, 'sem_get', 'auto_release');

        [$result, $message] = VmSem::get(
            $frame->vmContext,
            $key,
            $maxAcquire,
            $perm,
            $autoRelease
        );
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
        if ($argc < 1 || $argc > 4) {
            return JitSemGet::emitArgumentCountError($context, $argc);
        }

        return JitSemGet::invoke($context, $args);
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
