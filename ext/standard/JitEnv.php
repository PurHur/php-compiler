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
    /**
     * @return Value
     * (string on success, boolean false when unset)
     */
    public static function getenv(Context $context, Value $nameStr): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_getenv'),
            $nameStr,
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
        $mallocFn = $context->lookupFunction(
            \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $context->loadType
                ? 'malloc'
                : '__mm__malloc'
        );
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
        // putenv() retains the buffer; do not free (libc owns the assignment string).
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $status, $i32->constInt(0, false));
    }
}
