<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGetdate;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for getdate() via __compiler_getdate (JIT/AOT, issue #3510). */
final class JitGetdate
{
    public static function invoke(Context $context, ?JITVariable $timestamp = null): Value
    {
        StringGetdate::ensureLinked($context);

        $ts = null === $timestamp
            ? JitDate::time($context)
            : self::jitTimestampArg($context, $timestamp);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_getdate'),
            $ts,
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

        throw new \LogicException('getdate() timestamp must be an integer or null in this compiler build');
    }
}
