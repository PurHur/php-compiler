<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for urlencode() / rawurlencode() — percent-encoding without PHP builtins.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitUrlencode
{
    public static function encode(Context $context, Value $strPtr, bool $formEncoding): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $three = $i64->constInt(3, false);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'urlenc_empty');
        $workBlock = BasicBlockHelper::append($context, 'urlenc_work');
        $doneBlock = BasicBlockHelper::append($context, 'urlenc_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $outLenSlot = $context->builder->alloca($i64, 1, 'urlenc_out_len');
        $context->builder->store($zero, $outLenSlot);
        self::accumulateLength($context, $charPtr, $len, $outLenSlot, $formEncoding);

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store(
            $outLen,
            $context->builder->structGep($dest, $map['length'])
        );
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $writePosSlot = $context->builder->alloca($i64, 1, 'urlenc_write_pos');
        $context->builder->store($zero, $writePosSlot);
        self::writeEncoded($context, $charPtr, $len, $destPtr, $writePosSlot, $formEncoding);
        $workDoneBlock = BasicBlockHelper::append($context, 'urlenc_work_done');
        $context->builder->branch($workDoneBlock);

        $context->builder->positionAtEnd($workDoneBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $workDoneBlock);

        return $result;
    }

    private static function accumulateLength(
        Context $context,
        Value $charPtr,
        Value $len,
        Value $outLenSlot,
        bool $formEncoding
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $three = $i64->constInt(3, false);

        $idxSlot = $context->builder->alloca($i64, 1, 'urlenc_len_idx');
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'urlenc_len_head');
        $body = BasicBlockHelper::append($context, 'urlenc_len_body');
        $tail = BasicBlockHelper::append($context, 'urlenc_len_tail');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $tail, $body);

        $context->builder->positionAtEnd($body);
        $byte = self::loadByte($context, $charPtr, $idx);
        $add = self::encodedByteLength($context, $byte, $formEncoding);
        $cur = $context->builder->load($outLenSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($cur, $add),
            $outLenSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($tail);
    }

    private static function writeEncoded(
        Context $context,
        Value $charPtr,
        Value $len,
        Value $destPtr,
        Value $writePosSlot,
        bool $formEncoding
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $hexTable = $context->builder->pointerCast(
            $context->constantFromString('0123456789ABCDEF'),
            $context->getTypeFromString('char*')
        );

        $idxSlot = $context->builder->alloca($i64, 1, 'urlenc_wr_idx');
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'urlenc_wr_head');
        $body = BasicBlockHelper::append($context, 'urlenc_wr_body');
        $tail = BasicBlockHelper::append($context, 'urlenc_wr_tail');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $tail, $body);

        $context->builder->positionAtEnd($body);
        $byte = self::loadByte($context, $charPtr, $idx);
        $pos = $context->builder->load($writePosSlot);
        $isUnreserved = self::isUnreservedByte($context, $byte);
        $plainBlock = BasicBlockHelper::append($context, 'urlenc_wr_plain');
        $maybeSpaceBlock = BasicBlockHelper::append($context, 'urlenc_wr_maybe_space');
        $context->builder->branchIf($isUnreserved, $plainBlock, $maybeSpaceBlock);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($byte, $context->builder->inBoundsGEP($destPtr, $pos));
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $one),
            $writePosSlot
        );
        $afterPlain = BasicBlockHelper::append($context, 'urlenc_wr_after_plain');
        $context->builder->branch($afterPlain);

        $context->builder->positionAtEnd($maybeSpaceBlock);
        if ($formEncoding) {
            $isSpace = $context->builder->icmp(
                Builder::INT_EQ,
                $byte,
                $i8->constInt(32, false)
            );
            $plusBlock = BasicBlockHelper::append($context, 'urlenc_wr_plus');
            $pctBlock = BasicBlockHelper::append($context, 'urlenc_wr_pct');
            $context->builder->branchIf($isSpace, $plusBlock, $pctBlock);
            $context->builder->positionAtEnd($plusBlock);
            $context->builder->store(
                $i8->constInt(43, false),
                $context->builder->inBoundsGEP($destPtr, $pos)
            );
            $context->builder->store(
                $context->builder->addNoSignedWrap($pos, $one),
                $writePosSlot
            );
            $context->builder->branch($afterPlain);
            $context->builder->positionAtEnd($pctBlock);
            self::writePercentHex($context, $byte, $destPtr, $writePosSlot, $pos, $hexTable);
            $context->builder->branch($afterPlain);
        } else {
            self::writePercentHex($context, $byte, $destPtr, $writePosSlot, $pos, $hexTable);
            $context->builder->branch($afterPlain);
        }

        $context->builder->positionAtEnd($afterPlain);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($tail);
    }

    private static function writePercentHex(
        Context $context,
        Value $byte,
        Value $destPtr,
        Value $writePosSlot,
        Value $pos,
        Value $hexTable
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);

        $byteI32 = $context->builder->zExt($byte, $i32);
        $hi = $context->builder->lShr($byteI32, $i32->constInt(4, false));
        $lo = $context->builder->bitwiseAnd($byteI32, $i32->constInt(0x0F, false));

        $context->builder->store(
            $i8->constInt(37, false),
            $context->builder->inBoundsGEP($destPtr, $pos)
        );
        $p1 = $context->builder->addNoSignedWrap($pos, $one);
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $hi)),
            $context->builder->inBoundsGEP($destPtr, $p1)
        );
        $p2 = $context->builder->addNoSignedWrap($pos, $two);
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $lo)),
            $context->builder->inBoundsGEP($destPtr, $p2)
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($pos, $three),
            $writePosSlot
        );
    }

    private static function loadByte(Context $context, Value $charPtr, Value $idx): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $idxI32 = $context->builder->truncOrBitCast($idx, $i32);

        return $context->builder->load($context->builder->gep($charPtr, $idxI32));
    }

    private static function encodedByteLength(Context $context, Value $byte, bool $formEncoding): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $three = $i64->constInt(3, false);

        $isUnreserved = self::isUnreservedByte($context, $byte);
        $singleByte = $isUnreserved;
        if ($formEncoding) {
            $isSpace = $context->builder->icmp(
                Builder::INT_EQ,
                $byte,
                $i8->constInt(32, false)
            );
            $singleByte = $context->builder->or($isUnreserved, $isSpace);
        }

        return $context->builder->select($singleByte, $one, $three);
    }

    private static function isUnreservedByte(Context $context, Value $byte): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $byte, $i8->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $byte, $i8->constInt(57, false))
        );
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $byte, $i8->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $byte, $i8->constInt(90, false))
        );
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $byte, $i8->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $byte, $i8->constInt(122, false))
        );
        $isDash = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(45, false));
        $isUnder = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(95, false));
        $isDot = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(46, false));
        $isTilde = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(126, false));

        return $context->builder->or(
            $isDigit,
            $context->builder->or(
                $isUpper,
                $context->builder->or(
                    $isLower,
                    $context->builder->or($isDash, $context->builder->or($isUnder, $context->builder->or($isDot, $isTilde)))
                )
            )
        );
    }
}
