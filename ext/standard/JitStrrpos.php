<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strrpos() — repeated strstr from offset for last match.
 *
 * Not found is represented as 0 (native long). VM mode returns boolean false instead.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitStrrpos
{
    public const NOT_FOUND = 0;

    public static function find(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $off = null === $offset ? $zero : self::offsetAsSignedI64($context, $offset);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strrpos'),
            $haystack,
            $needle,
            $off
        );
    }

    /** LLVM constInt(..., false) stores negative literals unsigned; read with SExt (issue #4104). */
    private static function offsetAsSignedI64(Context $context, Value $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $lib = $context->llvm->lib;
        $llvmValue = $offset->value;
        if (null !== $lib->LLVMIsAConstantInt($llvmValue)) {
            $signed = (int) $lib->LLVMConstIntGetSExtValue($llvmValue);

            return $i64->constInt($signed, true);
        }

        return $offset->typeOf() === $i64
            ? $offset
            : $context->builder->sExtOrBitCast($offset, $i64);
    }
}
