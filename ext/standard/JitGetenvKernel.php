<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for phpc_getenv_kernel() — thin libc getenv(3) (#20644).
 *
 * Nested leaf inside GetenvJitHelper only (user-script AOT always goes through
 * {@see GetenvJitHelper} via {@see \PHPCompiler\JIT\Builtin\StringGetenv}).
 * php-src: ext/standard/basic_functions.c — zif_getenv
 */
final class JitGetenvKernel
{
    /** @return Value __string__* — null when getenv(3) returns NULL */
    public static function invoke(Context $context, Value $nameStr): Value
    {
        LibcExtern::register($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        $strMap = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        $hitBb = $fn->appendBasicBlock('getenv_kernel_hit');
        $missBb = $fn->appendBasicBlock('getenv_kernel_miss');
        $doneBb = $fn->appendBasicBlock('getenv_kernel_done');

        $nameBytes = $context->builder->structGep($nameStr, $strMap['value']);
        $envRaw = $context->builder->call($context->lookupFunction('getenv'), $nameBytes);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $envRaw, $i8p->constNull());
        $context->builder->branchIf($isNull, $missBb, $hitBb);

        $context->builder->positionAtEnd($hitBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $envRaw);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $envRaw
        );
        $hitEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($missBb);
        $nullStr = $strPtrTy->constNull();
        $missEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtrTy, 'getenv_kernel_result');
        $phi->addIncoming($owned, $hitEnd);
        $phi->addIncoming($nullStr, $missEnd);

        return $phi;
    }

    /**
     * @deprecated Prefer {@see invoke}; retained for any residual out-param ABI callers.
     *
     * Emit libc getenv lookup into out __value__; builder must be positioned at the entry block.
     * ABI: void (__string__* name, int8 localOnly, __value__* out)
     */
    public static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $nameStr = $fn->getParam(0);
        $localOnly = $fn->getParam(1);
        $out = $fn->getParam(2);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');

        $isLocal = $context->builder->icmp(Builder::INT_NE, $localOnly, $i8->constInt(0, false));
        $libcBb = $fn->appendBasicBlock('getenv_kernel_lookup');
        $missingBb = $fn->appendBasicBlock('getenv_kernel_missing');
        $hitBb = $fn->appendBasicBlock('getenv_kernel_write');
        $doneBb = $fn->appendBasicBlock('getenv_kernel_done');
        $context->builder->branchIf($isLocal, $missingBb, $libcBb);

        $context->builder->positionAtEnd($libcBb);
        $owned = self::invoke($context, $nameStr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $owned,
            $context->getTypeFromString('__string__*')->constNull()
        );
        $context->builder->branchIf($isNull, $missingBb, $hitBb);

        $context->builder->positionAtEnd($hitBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($missingBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }
}
