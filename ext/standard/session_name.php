<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * session_name() — get or set the session cookie name (issue #1184).
 *
 * Excess argc → Zend ArgumentCountError (#30684; php-src ext/session/session.c).
 * Reflection stub: ?string $name = null → string|false (#31423; session.stub.php via
 * {@see \PHPCompiler\BuiltinInternalArgInfo} / {@see \PHPCompiler\BuiltinInternalDefaultValues}).
 */
class session_name extends Internal
{
    public function __construct()
    {
        parent::__construct('session_name');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..1 (#30684; ext/session/session.stub.php).
        $this->requireAtMostArgCount($frame, 'session_name', 1);
        $argc = \count($frame->calledArgs);
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
        if (!VmSession::canChangeName($frame)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
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
        // Catchable ArgumentCountError under AOT try/catch (#30684).
        if (!$this->requireAtMostJitArgCount($context, $args, 'session_name', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return \call_user_func_array([JitSessionName::class, 'invoke'], array_merge([$context], $args));
    }
}
