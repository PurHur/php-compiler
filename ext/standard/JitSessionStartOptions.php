<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\Builtin\SessionStartOptionsRuntime;
use PHPLLVM\Value;

/** JIT lowering for session_start($options) (#18457). */
final class JitSessionStartOptions
{
    public static function invoke(Context $context, JITVariable $options): Value
    {
        if ($options->compileTimeEmptyArrayLiteral) {
            return JitSessionStart::invoke($context);
        }

        SessionStartOptionsRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $slot);
        // ABI is (__value__* out, __value__* options) — pass a value pointer, not a
        // loaded %__value__ struct (#33945 / Module.php:180 call signature).
        $optionsPtr = JitValueBox::valuePtrFromVariable($context, $options);
        $context->builder->call(
            $context->lookupFunction(SessionStartOptionsRuntime::ABI),
            $outPtr,
            $optionsPtr
        );

        return $outPtr;
    }
}
