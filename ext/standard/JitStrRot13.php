<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for str_rot13() — in-place ROT13 on a separate __string__ copy. */
final class JitStrRot13
{
    public static function transform(Context $context, Value $strPtr): void
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $iSlot = $context->builder->alloca($i64, 1, 'str_rot13_i');
        $context->builder->store($zero, $iSlot);

        $done = BasicBlockHelper::append($context, 'str_rot13_done');
        $loopHead = BasicBlockHelper::append($context, 'str_rot13_head');
        $loopBody = BasicBlockHelper::append($context, 'str_rot13_body');
        $context->builder->branch($loopHead);

        $lowerMin = $i32->constInt(97, false);
        $lowerMid = $i32->constInt(110, false);
        $lowerMax = $i32->constInt(122, false);
        $upperMin = $i32->constInt(65, false);
        $upperMid = $i32->constInt(78, false);
        $upperMax = $i32->constInt(90, false);
        $thirteen = $i32->constInt(13, false);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $atChar = $context->builder->gep($charPtr, $i);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);
        $isLowerFirst = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $lowerMin),
            $context->builder->icmp(Builder::INT_SLT, $chI32, $lowerMid)
        );
        $isLowerSecond = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $lowerMid),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $lowerMax)
        );
        $isUpperFirst = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $upperMin),
            $context->builder->icmp(Builder::INT_SLT, $chI32, $upperMid)
        );
        $isUpperSecond = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $upperMid),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $upperMax)
        );
        $negThirteen = $i32->constInt(-13, true);
        $rotOrd = $chI32;
        $rotOrd = $context->builder->select(
            $isLowerFirst,
            $context->builder->addNoSignedWrap($rotOrd, $thirteen),
            $rotOrd
        );
        $rotOrd = $context->builder->select(
            $isLowerSecond,
            $context->builder->addNoSignedWrap($rotOrd, $negThirteen),
            $rotOrd
        );
        $rotOrd = $context->builder->select(
            $isUpperFirst,
            $context->builder->addNoSignedWrap($rotOrd, $thirteen),
            $rotOrd
        );
        $rotOrd = $context->builder->select(
            $isUpperSecond,
            $context->builder->addNoSignedWrap($rotOrd, $negThirteen),
            $rotOrd
        );
        $newCh = $context->builder->truncOrBitCast($rotOrd, $ch->typeOf());
        $context->builder->store($newCh, $atChar);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }
}
