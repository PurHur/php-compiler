<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for vsprintf() (issue #3190).
 */
final class JitVsprintf
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('vsprintf() requires exactly two arguments');
        }
        $fmt = JitStringArg::lower($context, $args[0], 'vsprintf() format');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $args[1]);
        $num = ArrayBuiltinHelper::getNumElements($context, $ht);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $emptyBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_empty');
        $packBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_pack');
        $doneBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $packBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $nullArgv = $context->builder->pointerCast(
            $zero,
            $context->getTypeFromString('__value__*')
        );
        $emptyOut = $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $zero,
            $nullArgv
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($packBlock);
        $valueTy = $context->getTypeFromString('__value__');
        $i32 = $context->getTypeFromString('int32');
        $elemSize = $context->builder->ptrToInt(
            $context->builder->gep(
                $valueTy->pointerType(0)->constNull(),
                $i32->constInt(1, false)
            ),
            $sizeT
        );
        $argvBytes = $context->builder->mul($elemSize, $context->builder->intCast($num, $sizeT));
        $argvRaw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $argvBytes
        );
        $argvPtr = $context->builder->pointerCast(
            $argvRaw,
            $context->getTypeFromString('__value__*')
        );
        $map = $context->structFieldMap['__hashtable__'];
        $valuesPtr = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($context->builder->intCast($zero, $sizeT), $idxAlloca);
        $loopHead = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_loop_head');
        $loopBody = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_loop_body');
        $loopExit = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_loop_exit');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_UGE, $context->builder->intCast($idx, $i64), $num);
        $context->builder->branchIf($done, $loopExit, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $idx64 = $context->builder->intCast($idx, $i64);
        $argvSlot = $context->builder->inBoundsGEP($argvPtr, $idx64);
        $srcSlot = $context->builder->inBoundsGEP($valuesPtr, $idx);
        JitValueBox::copyFromPointer($context, $argvSlot, $srcSlot);
        $one = $sizeT->constInt(1, false);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxAlloca
        );
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopExit);
        $packedOut = $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $num,
            $argvPtr
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $argvRaw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $phi->addIncoming($emptyOut, $emptyBlock);
        $phi->addIncoming($packedOut, $loopExit);

        return $phi;
    }
}
