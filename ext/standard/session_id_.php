<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * session_id() — get or set the active session id (issue #1183).
 *
 * Excess argc → Zend ArgumentCountError (#30684; php-src ext/session/session.c).
 */
class session_id_ extends Internal
{
    public function __construct()
    {
        parent::__construct('session_id');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..1 (#30684; ext/session/session.stub.php).
        $this->requireAtMostArgCount($frame, 'session_id', 1);
        $argc = \count($frame->calledArgs);
        if (0 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->string(VmSession::getId());
            }

            return;
        }
        $idVar = $frame->calledArgs[0]->resolveIndirect();
        $id = VmString::coerceNullableStringBuiltinArg($idVar, 'session_id', 0, 'id');
        if (null === $id) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->string(VmSession::getId());
            }

            return;
        }
        if (!VmSession::canChangeId($frame)) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->bool(false);

            return;
        }
        $result = VmSession::setId($id);
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
        if (!$this->requireAtMostJitArgCount($context, $args, 'session_id', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return \call_user_func_array([JitSessionId::class, 'invoke'], array_merge([$context], $args));
    }
}
