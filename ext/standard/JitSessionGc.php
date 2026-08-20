<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SessionGcRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;

/** PHP lowering for session_gc() — {@see __phpc_session_gc_apply} (#6006). */
final class JitSessionGc
{
    public static function invoke(Context $context): \PHPLLVM\Value
    {
        // STANDALONE Type::register skips SessionGcRuntime::ensureLinked (#32994).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        SessionGcRuntime::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_gc_apply'),
            $ptr
        );

        return $ptr;
    }
}
