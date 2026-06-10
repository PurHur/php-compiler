<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGetdate;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for getdate() via StringGetdate (__compiler_getdate, #5256). */
final class JitGetdate
{
    public static function invoke(Context $context, ?JITVariable $timestamp = null): Value
    {
        StringGetdate::ensureLinked($context);

        $ts = null === $timestamp
            ? JitDate::time($context)
            : JitDateTimestampArg::lowerNullable(
                $context,
                $timestamp,
                'getdate',
                1,
                'timestamp',
                JitDate::time($context)
            );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_getdate'),
            $ts,
            $ptr
        );

        return $ptr;
    }

}
