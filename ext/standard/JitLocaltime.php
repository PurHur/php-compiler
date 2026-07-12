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
            : JitDateTimestampArg::lowerNullable(
                $context,
                $timestamp,
                'localtime',
                1,
                'timestamp',
                JitDate::time($context)
            );

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
}
