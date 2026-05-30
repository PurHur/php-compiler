<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helpers for getenv() and putenv() via libc.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitEnv
{
    private static function lookupMalloc(Context $context): Value
    {
        try {
            return $context->lookupFunction('malloc');
        } catch (\LogicException) {
            $i8p = $context->getTypeFromString('int8*');
            $i64 = $context->getTypeFromString('int64');
            $ft = $context->context->functionType($i8p, false, $i64);
            $fn = $context->module->addFunction('malloc', $ft);
            $context->registerFunction('malloc', $fn);

            return $fn;
        }
    }

    /**
     * @return Value
     * (string on success, boolean false when unset)
     */
    public static function getenv(Context $context, Value $nameStr, Value $localOnlyI8): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_getenv'),
            $nameStr,
            $localOnlyI8,
            $ptr
        );

        return $ptr;
    }

    public static function putenv(Context $context, Value $assignmentStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $len = $context->builder->load(
            $context->builder->structGep($assignmentStr, $map['length'])
        );
        $bytes = $context->builder->structGep($assignmentStr, $map['value']);
        $bufLen = $context->builder->add($len, $one);
        // putenv() retains the buffer for the lifetime of the process.
        // Use libc malloc rather than the managed allocator so the assignment string is never reclaimed.
        $mallocFn = self::lookupMalloc($context);
        $buf = $context->builder->call($mallocFn, $bufLen);
        $cStr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cStr, $bytes, $len, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($cStr, $len)
        );
        $status = $context->builder->call(
            $context->lookupFunction('putenv'),
            $cStr
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_env_register_putenv'),
            $cStr
        );
        // putenv() retains the buffer; do not free (libc owns the assignment string).
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $status, $i32->constInt(0, false));
    }
}
