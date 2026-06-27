<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** session_name() — get or set the session cookie name (issue #1184). */
class session_name extends Internal
{
    public function __construct()
    {
        parent::__construct('session_name');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('session_name() accepts at most one argument in this compiler build');
        }
        if (0 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->string(VmSession::getName());
            }

            return;
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        $name = VmString::coerceNullableStringBuiltinArg($nameVar, 'session_name', 0, 'name');
        if (null === $name) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->string(VmSession::getName());
            }

            return;
        }
        if (VmSession::isRejectedSessionName($name)) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    \sprintf(VmSession::EMPTY_NAME_WARNING, $name),
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->string(VmSession::getName());
            }

            return;
        }
        $result = VmSession::setName($name);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('session_name() accepts at most one argument in this compiler build');
        }

        return \call_user_func_array([JitSessionName::class, 'invoke'], array_merge([$context], $args));
    }
}
