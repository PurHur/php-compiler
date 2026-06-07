<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringLocaltime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for localtime() via StringLocaltime (__compiler_localtime, #6812). */
final class JitLocaltime
{
    public static function invoke(Context $context, ?JITVariable $timestamp, Value $associative): Value
    {
        StringLocaltime::ensureLinked($context);

        $ts = null === $timestamp
            ? JitDate::time($context)
            : self::jitTimestampArg($context, $timestamp);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_localtime'),
            $ts,
            $associative,
            $ptr
        );

        return $ptr;
    }

    private static function jitTimestampArg(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('localtime() timestamp must be an integer or null in this compiler build');
    }
}
