<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for deferred/user-script AOT getenv — thin libc getenv (#19373).
 *
 * Nested {@see GetenvJitHelper} does not run under inventory/bootstrap/user-script
 * defer (#16075 / {@see \PHPCompiler\JIT\Builtin\StreamIoRuntime::shouldDeferHeavyStreamIoEmitters});
 * this kernel mirrors the former Builtin libc stub from ext/ not lib/JIT/Builtin/.
 * php-src: ext/standard/basic_functions.c — zif_getenv
 */
final class JitGetenvKernel
{
    /**
     * Emit libc getenv lookup into out __value__; builder must be positioned at the entry block.
     *
     * ABI: void (__string__* name, int8 localOnly, __value__* out)
     */
    public static function emitBody(Context $context, LlvmFunction $fn): void
    {
        LibcExtern::register($context);

        $nameStr = $fn->getParam(0);
        $localOnly = $fn->getParam(1);
        $out = $fn->getParam(2);
        $valMap = $context->structFieldMap['__value__'];
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);

        $isLocal = $context->builder->icmp(Builder::INT_NE, $localOnly, $i8->constInt(0, false));
        $libcBb = $fn->appendBasicBlock('getenv_kernel_lookup');
        $missingBb = $fn->appendBasicBlock('getenv_kernel_missing');
        $hitBb = $fn->appendBasicBlock('getenv_kernel_hit');
        $doneBb = $fn->appendBasicBlock('getenv_kernel_done');
        $context->builder->branchIf($isLocal, $missingBb, $libcBb);

        $context->builder->positionAtEnd($libcBb);
        $nameBytes = $context->builder->structGep($nameStr, $strMap['value']);
        $envRaw = $context->builder->call($context->lookupFunction('getenv'), $nameBytes);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $envRaw, $i8p->constNull());
        $context->builder->branchIf($isNull, $missingBb, $hitBb);

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
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($missingBb);
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($out, $valMap['type'])
        );
        $valueField = $context->builder->structGep($out, $valMap['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $zero
        );
        $context->builder->store($i8->constInt(0, false), $firstByte);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }
}
