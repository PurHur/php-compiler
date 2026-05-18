<?php

declare(strict_types=1);

/**
 * LLVM helper to concatenate two __string__ values (AOT/JIT).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStringConcat
{
    public static function concat(Context $context, Value $left, Value $right): Value
    {
        $map = $context->structFieldMap['__string__'];
        $leftLen = $context->builder->load(
            $context->builder->structGep($left, $map['length'])
        );
        $rightLen = $context->builder->load(
            $context->builder->structGep($right, $map['length'])
        );
        $leftPtr = $context->builder->structGep($left, $map['value']);
        $rightPtr = $context->builder->structGep($right, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $totalLen = $context->builder->add($leftLen, $rightLen);

        $emptyBlock = BasicBlockHelper::append($context, 'concat_empty');
        $workBlock = BasicBlockHelper::append($context, 'concat_work');
        $doneBlock = BasicBlockHelper::append($context, 'concat_done');
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $totalLen, $zero);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $totalLen);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store(
            $totalLen,
            $context->builder->structGep($dest, $map['length'])
        );
        $context->intrinsic->memcpy($destPtr, $leftPtr, $leftLen, false);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $leftLen),
            $rightPtr,
            $rightLen,
            false
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $workBlock);

        return $result;
    }
}
