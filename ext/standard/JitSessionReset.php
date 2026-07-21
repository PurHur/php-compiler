<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SessionLifecycleRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;

/** PHP lowering for session_reset() — {@see __phpc_session_reset_apply} (#6002). */
final class JitSessionReset
{
    public static function invoke(Context $context): \PHPLLVM\Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        SessionLifecycleRuntime::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_reset_apply'),
            $ptr
        );

        return $ptr;
    }
}
