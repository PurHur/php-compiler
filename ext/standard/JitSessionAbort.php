<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\SessionLifecycleRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;

/** PHP lowering for session_abort() — {@see __phpc_session_abort_apply} (#6002). */
final class JitSessionAbort
{
    public static function invoke(Context $context): \PHPLLVM\Value
    {
        SessionLifecycleRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_abort_apply'),
            $ptr
        );

        return $ptr;
    }
}
