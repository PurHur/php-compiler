<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for umask() via __compiler_umask (libc umask(2), #3226). */
final class JitUmask
{
    /** @return Value previous mask as native long */
    public static function invoke(Context $context, ?Value $maskLong): Value
    {
        $i64 = $context->getTypeFromString('int64');

        if (null === $maskLong) {
            return $context->builder->call($context->lookupFunction('__compiler_umask_get'));
        }

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_umask'),
            $maskLong
        );

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }
}
