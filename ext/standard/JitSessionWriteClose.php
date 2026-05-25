<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;

/** PHP lowering for session_write_close() — {@see __phpc_session_write_close_apply} (#1885). */
final class JitSessionWriteClose
{
    public static function invoke(Context $context): \PHPLLVM\Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_write_close_apply'),
            $ptr
        );

        return $ptr;
    }
}
