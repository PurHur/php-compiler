<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for glob() and scandir() via {@see __phpc_glob} / {@see __phpc_scandir}. */
final class JitFsGlob
{
    /** @return Value __value__* (list array, or boolean false on failure) */
    public static function glob(Context $context, Value $patternStr, Value $flagsI32): Value
    {
        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_glob'),
            $patternStr,
            $flagsI32
        );

        return self::wrapHashtableOrFalse($context, $ht, 'glob');
    }

    /** @return Value __value__* (list array, or boolean false on failure) */
    public static function scandir(Context $context, Value $pathStr, Value $sortI32): Value
    {
        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_scandir'),
            $pathStr,
            $sortI32
        );

        return self::wrapHashtableOrFalse($context, $ht, 'scandir');
    }

    private static function wrapHashtableOrFalse(Context $context, Value $ht, string $id): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, $id.'_fail');
        $okBlock = BasicBlockHelper::append($context, $id.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $id.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
