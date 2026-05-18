<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for number_format() (C-style locale subset).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitNumberFormat
{
    public static function format(
        Context $context,
        Value $number,
        Value $decimals,
        Value $decimalSeparator,
        Value $thousandsSeparator
    ): Value {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');

        $zeroD = $double->constReal(0.0);
        $isNeg = $context->builder->fcmp(Builder::REAL_OLT, $number, $zeroD);
        $absNum = $context->builder->call($context->lookupFunction('fabs'), $number);

        $bufSize = $sizeT->constInt(128, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%.*f'),
            $charPtr
        );
        $decI32 = $context->builder->truncOrBitCast($decimals, $i32);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $decI32,
            $absNum
        );
        $bodyLen = $context->builder->zExt($written, $i64);

        $dotPos = self::findDot($context, $bufChar, $bodyLen, $i64, $i8);
        $hasDot = $context->builder->icmp(Builder::INT_SLT, $dotPos, $bodyLen);
        $intLen = $dotPos;
        $fracStart = $context->builder->addNoSignedWrap($dotPos, $i64->constInt(1, false));
        $fracLen = $context->builder->sub($bodyLen, $fracStart);

        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $three = $i64->constInt(3, false);
        $thouSepCount = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $intLen, $three),
            $context->builder->signedDiv($context->builder->sub($intLen, $one), $three),
            $zero
        );

        $outLen = $context->builder->addNoSignedWrap($intLen, $thouSepCount);
        $outLen = $context->builder->select(
            $hasDot,
            $context->builder->addNoSignedWrap(
                $context->builder->addNoSignedWrap($outLen, $one),
                $fracLen
            ),
            $outLen
        );
        $outLen = $context->builder->select(
            $isNeg,
            $context->builder->addNoSignedWrap($outLen, $one),
            $outLen
        );

        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $map = $context->structFieldMap['__string__'];
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1, 'number_format_out_pos');
        $context->builder->store($zero, $posSlot);
        $srcIdxSlot = $context->builder->alloca($i64, 1, 'number_format_src_idx');
        $context->builder->store($zero, $srcIdxSlot);

        $negBlock = BasicBlockHelper::append($context, 'number_format_neg');
        $intBlock = BasicBlockHelper::append($context, 'number_format_int');
        $context->builder->branchIf($isNeg, $negBlock, $intBlock);

        $context->builder->positionAtEnd($negBlock);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(ord('-'), false), $context->builder->gep($destChars, $pos));
        $context->builder->store($one, $posSlot);
        $context->builder->branch($intBlock);

        $context->builder->positionAtEnd($intBlock);
        self::copyIntegerWithThousands(
            $context,
            $bufChar,
            $destChars,
            $intLen,
            $thousandsSeparator,
            $srcIdxSlot,
            $posSlot,
            $i64,
            $i8,
            $one,
            $zero,
            $three
        );

        $fracBlock = BasicBlockHelper::append($context, 'number_format_frac');
        $doneBlock = BasicBlockHelper::append($context, 'number_format_done');
        $context->builder->branchIf($hasDot, $fracBlock, $doneBlock);

        $context->builder->positionAtEnd($fracBlock);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($decimalSeparator, $context->builder->gep($destChars, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $fracIdxSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($fracStart, $fracIdxSlot);
        self::copyRange(
            $context,
            $bufChar,
            $destChars,
            $fracIdxSlot,
            $posSlot,
            $bodyLen,
            $i64,
            $one,
            $zero
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $dest;
    }

    private static function findDot(
        Context $context,
        Value $bufChar,
        Value $bodyLen,
        $i64,
        $i8
    ): Value {
        $idxSlot = $context->builder->alloca($i64, 1);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'number_format_find_head');
        $body = BasicBlockHelper::append($context, 'number_format_find_body');
        $next = BasicBlockHelper::append($context, 'number_format_find_next');
        $foundBlock = BasicBlockHelper::append($context, 'number_format_find_found');
        $notFoundBlock = BasicBlockHelper::append($context, 'number_format_find_not_found');
        $done = BasicBlockHelper::append($context, 'number_format_find_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $bodyLen);
        $context->builder->branchIf($atEnd, $notFoundBlock, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($bufChar, $idx));
        $isDot = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('.'), false));
        $context->builder->branchIf($isDot, $foundBlock, $next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($foundBlock);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($notFoundBlock);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($idx, $foundBlock);
        $phi->addIncoming($bodyLen, $notFoundBlock);

        return $phi;
    }

    private static function copyIntegerWithThousands(
        Context $context,
        Value $src,
        Value $dest,
        Value $intLen,
        Value $thouSep,
        Value $srcIdxSlot,
        Value $posSlot,
        $i64,
        $i8,
        Value $one,
        Value $zero,
        Value $three
    ): void {
        $digitsLeftSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($intLen, $digitsLeftSlot);
        $groupSlot = $context->builder->alloca($i64, 1);
        $mod = $context->builder->signedRem($intLen, $three);
        $firstGroup = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $mod, $zero),
            $three,
            $mod
        );
        $context->builder->store($firstGroup, $groupSlot);

        $loopHead = BasicBlockHelper::append($context, 'number_format_int_head');
        $loopBody = BasicBlockHelper::append($context, 'number_format_int_body');
        $loopDone = BasicBlockHelper::append($context, 'number_format_int_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $digitsLeft = $context->builder->load($digitsLeftSlot);
        $stop = $context->builder->icmp(Builder::INT_SLE, $digitsLeft, $zero);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $group = $context->builder->load($groupSlot);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $pos = $context->builder->load($posSlot);
        $ch = $context->builder->load($context->builder->gep($src, $srcIdx));
        $context->builder->store($ch, $context->builder->gep($dest, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($srcIdx, $one), $srcIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $group = $context->builder->sub($group, $one);
        $digitsLeft = $context->builder->sub($digitsLeft, $one);
        $context->builder->store($group, $groupSlot);
        $context->builder->store($digitsLeft, $digitsLeftSlot);

        $afterDigit = BasicBlockHelper::append($context, 'number_format_int_after_digit');
        $needsSep = $context->builder->icmp(Builder::INT_EQ, $group, $zero);
        $moreDigits = $context->builder->icmp(Builder::INT_SGT, $digitsLeft, $zero);
        $context->builder->branchIf(
            $context->builder->bitwiseAnd($needsSep, $moreDigits),
            $afterDigit,
            $loopHead
        );
        $context->builder->positionAtEnd($afterDigit);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($thouSep, $context->builder->gep($dest, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($three, $groupSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
    }

    private static function copyRange(
        Context $context,
        Value $src,
        Value $dest,
        Value $srcIdxSlot,
        Value $posSlot,
        Value $end,
        $i64,
        Value $one,
        Value $zero
    ): void {
        $head = BasicBlockHelper::append($context, 'number_format_copy_head');
        $body = BasicBlockHelper::append($context, 'number_format_copy_body');
        $done = BasicBlockHelper::append($context, 'number_format_copy_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($srcIdxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $end);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $pos = $context->builder->load($posSlot);
        $ch = $context->builder->load($context->builder->gep($src, $idx));
        $context->builder->store($ch, $context->builder->gep($dest, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $srcIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
