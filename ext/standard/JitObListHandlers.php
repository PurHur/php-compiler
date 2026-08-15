<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ObStatusRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_list_handlers() (issue #3588). */
final class JitObListHandlers
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity gated in ob_list_handlers::call via requireExactJitArgCount (#30683).
        ObStatusRuntime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $ht = $context->builder->call($context->lookupFunction('__phpc_ob_list_handlers_ht'));
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
