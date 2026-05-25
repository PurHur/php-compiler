<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;

/** PHP lowering for session_destroy() — {@see __phpc_session_destroy_apply} (#1182). */
final class JitSessionDestroy
{
    public static function invoke(Context $context): \PHPLLVM\Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_destroy_apply'),
            $ptr
        );

        return $ptr;
    }
}
