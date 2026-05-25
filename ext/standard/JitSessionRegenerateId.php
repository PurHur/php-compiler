<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** PHP lowering for session_regenerate_id() — {@see __phpc_session_regenerate_id_apply} (#1186). */
final class JitSessionRegenerateId
{
    public static function invoke(Context $context, ?JITVariable $deleteOld = null): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i8 = $context->getTypeFromString('int8');
        if (null === $deleteOld) {
            $deleteArg = $i8->constInt(0, false);
        } else {
            $bool = JitBoolArg::lower($context, $deleteOld, 'session_regenerate_id() argument #1');
            $deleteArg = $context->builder->zext($bool, $i8);
        }
        $context->builder->call(
            $context->lookupFunction('__phpc_session_regenerate_id_apply'),
            $ptr,
            $deleteArg
        );

        return $ptr;
    }
}
