<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** session_gc() — invoke session save-handler GC (php-src ext/session/session.c; #6006). */
class session_gc extends Internal
{
    public function __construct()
    {
        parent::__construct('session_gc');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'session_gc() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }

        if (!VmSession::isActive()) {
            $this->triggerWarning(
                $frame,
                'session_gc(): Session cannot be garbage collected when there is no active session'
            );
        }

        $result = VmSession::gc();
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('session_gc() expects exactly 0 arguments in this compiler build');
        }

        throw new \LogicException('session_gc() not implemented for JIT');
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
