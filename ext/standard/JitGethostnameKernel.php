<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_gethostname_kernel() — thin libc gethostname(2) (#21166).
 *
 * Nested leaf inside GethostnameJitHelper only (user-script AOT always goes through
 * {@see GethostnameJitHelper} via {@see \PHPCompiler\JIT\Builtin\StringGethostname}).
 * Returns `__string__*` — empty string when syscall fails (box to false at call site).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gethostname)
 */
final class JitGethostnameKernel
{
    /** php-src HOST_NAME_MAX; Linux gethostname(2) uses 256-byte buffer in basic_functions.c. */
    private const BUF_SIZE = 256;

    /** @return Value `__string__*` — empty when unavailable */
    public static function invoke(Context $context): Value
    {
        LibcExtern::register($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtrTy = $context->getTypeFromString('__string__*');

        $bufType = $i8->arrayType(self::BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'gethostname_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $ret = $context->builder->call(
            $context->lookupFunction('gethostname'),
            $bufPtr,
            $sizeT->constInt(self::BUF_SIZE, false)
        );
        $syscallFailed = $context->builder->icmp(Builder::INT_NE, $ret, $i32->constInt(0, false));
        $firstByte = $context->builder->load($bufPtr);
        $emptyFailed = $context->builder->icmp(Builder::INT_EQ, $firstByte, $i8->constInt(0, false));
        $failed = $context->builder->or($syscallFailed, $emptyFailed);

        $failBb = $fn->appendBasicBlock('gethostname_kernel_fail');
        $okBb = $fn->appendBasicBlock('gethostname_kernel_ok');
        $doneBb = $fn->appendBasicBlock('gethostname_kernel_done');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $bufPtr
        );
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($failBb);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtrTy, 'gethostname_kernel_result');
        $phi->addIncoming($owned, $okEnd);
        $phi->addIncoming($empty, $failEnd);

        return $phi;
    }
}
