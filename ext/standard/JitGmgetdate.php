<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGmgetdate;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for gmgetdate() via StringGmgetdate (__compiler_gmgetdate, #7001). */
final class JitGmgetdate
{
    public static function invoke(Context $context, ?JITVariable $timestamp = null): Value
    {
        StringGmgetdate::ensureLinked($context);

        $ts = null === $timestamp
            ? JitDate::time($context)
            : self::jitTimestampArg($context, $timestamp);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_gmgetdate'),
            $ts,
            $ptr
        );

        return $ptr;
    }

    private static function jitTimestampArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('gmgetdate() timestamp must be an integer or null in this compiler build');
    }
}
