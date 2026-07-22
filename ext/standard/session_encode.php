<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** session_encode() — encode $_SESSION as php handler blob (php-src ext/session/session.c; #6086, #21952). */
class session_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('session_encode');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError('session_encode() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        if (!VmSession::isActive()) {
            $this->triggerWarning(
                $frame,
                'session_encode(): Cannot encode non-existent session'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::encode($ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('session_encode() expects exactly 0 arguments in this compiler build');
        }

        return JitSessionEncode::invoke($context, ...$args);
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
