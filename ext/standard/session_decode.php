<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** session_decode() — hydrate $_SESSION from php handler blob (php-src ext/session/session.c; #6086, #21952). */
class session_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('session_decode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('session_decode() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        InternalStrictArg::requireString($frame, 0, 'session_decode', 'data');
        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'session_decode',
            0,
            'data'
        );
        if (!VmSession::isActive()) {
            $this->triggerWarning(
                $frame,
                'session_decode(): Session data cannot be decoded when there is no active session'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $frame->returnVar->bool(VmSession::decode($ctx, $data));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('session_decode() expects exactly 1 argument in this compiler build');
        }

        return JitSessionDecode::invoke($context, ...$args);
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
