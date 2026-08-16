<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** session_regenerate_id() — rotate session id (issue #1186; null soft-DEP #31444). */
class session_regenerate_id extends Internal
{
    public function __construct()
    {
        parent::__construct('session_regenerate_id');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'session_regenerate_id() expects at most 1 argument, '.$argc.' given'
            );
        }
        $deleteOld = false;
        if (1 === $argc) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31444).
            $deleteOld = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                0,
                'session_regenerate_id',
                1,
                'delete_old_session'
            );
        }
        if (!VmSession::isActive()) {
            $this->triggerWarning($frame, VmSession::REGENERATE_NO_SESSION_WARNING);
        }
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::regenerateId($ctx, $deleteOld);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('session_regenerate_id() accepts at most one argument in this compiler build');
        }

        return JitSessionRegenerateId::invoke($context, $args[0] ?? null);
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
