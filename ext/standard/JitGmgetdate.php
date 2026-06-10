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
            : JitDateTimestampArg::lowerNullable(
                $context,
                $timestamp,
                'gmgetdate',
                1,
                'timestamp',
                JitDate::time($context)
            );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_gmgetdate'),
            $ts,
            $ptr
        );

        return $ptr;
    }

}
