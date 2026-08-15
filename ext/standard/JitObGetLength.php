<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ObOutputRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_get_length() (issue #3236). */
final class JitObGetLength
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity gated in ob_get_length::call via requireExactJitArgCount (#30683).
        ObOutputRuntime::ensureObStackLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_get_length'),
            $ptr
        );

        return $ptr;
    }
}
