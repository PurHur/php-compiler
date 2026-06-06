<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for umask() via libc umask(2) (#3226, #6871). */
final class JitUmask
{
    /** @return Value previous mask as native long */
    public static function invoke(Context $context, ?Value $maskLong): Value
    {
        self::ensureLibcUmask($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fnUmask = $context->lookupFunction('umask');

        if (null === $maskLong) {
            $probeMask = $i32->constInt(0777, false);
            $old = $context->builder->call($fnUmask, $probeMask);
            $oldI32 = $old->typeOf() === $i32 ? $old : $context->builder->trunc($old, $i32);
            $context->builder->call($fnUmask, $oldI32);

            return $old->typeOf() === $i64 ? $old : $context->builder->zExt($old, $i64);
        }

        $maskI32 = $maskLong->typeOf() === $i32
            ? $maskLong
            : $context->builder->trunc($maskLong, $i32);
        $raw = $context->builder->call($fnUmask, $maskI32);

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureLibcUmask(Context $context): void
    {
        try {
            $context->lookupFunction('umask');
        } catch (\Throwable $e) {
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i32);
            $fn = $context->module->addFunction('umask', $ft);
            $context->registerFunction('umask', $fn);
        }
    }
}
