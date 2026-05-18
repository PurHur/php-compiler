<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helper for urlencode() and rawurlencode().
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitUrlencode
{
    public static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('urlencode() only supports strings in this compiler build');
    }

    public static function encode(Context $context, Value $strPtr, bool $formEncoding): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $outLenSlot = $context->builder->alloca($i64, 1, 'urlenc_out_len');
        $context->builder->store($zero, $outLenSlot);
        self::accumulateOutputLength($context, $charPtr, $len, $outLenSlot, $formEncoding);
        $outLen = $context->builder->load($outLenSlot);

        $emptyBlock = BasicBlockHelper::append($context, 'urlenc_finalize_empty');
        $fillBlock = BasicBlockHelper::append($context, 'urlenc_finalize_fill');
        $doneBlock = BasicBlockHelper::append($context, 'urlenc_finalize_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $outLen, $zero);
        $context->builder->branchIf($isEmpty, $emptyBlock, $fillBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($fillBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store(
            $outLen,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $destPtr = $context->builder->structGep($dest, $destMap['value']);
        $writeDoneBlock = self::writeEncoded($context, $charPtr, $len, $destPtr, $formEncoding, $doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $writeDoneBlock);

        return $result;
    }

    private static function accumulateOutputLength(
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

        $loopHead = BasicBlockHelper::append($context, 'urlenc_len_head');
        $loopBody = BasicBlockHelper::append($context, 'urlenc_len_body');
        $loopDone = BasicBlockHelper::append($context, 'urlenc_len_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $byte = $context->builder->load($context->builder->gep($charPtr, $idx));
        $add = self::encodedByteLength($context, $byte, $formEncoding);
        $cur = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($cur, $add), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
    }

    private static function writeEncoded(
        Context $context,
        Value $charPtr,
        Value $len,
        Value $destPtr,
        bool $formEncoding,
        \PHPLLVM\BasicBlock $afterBlock
    ): \PHPLLVM\BasicBlock {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $hexTable = $context->builder->pointerCast(
            $context->constantFromString('0123456789ABCDEF'),
            $context->getTypeFromString('char*')
        );

        $idxSlot = $context->builder->alloca($i64, 1, 'urlenc_idx');
        $posSlot = $context->builder->alloca($i64, 1, 'urlenc_pos');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $posSlot);

        $loopHead = BasicBlockHelper::append($context, 'urlenc_write_head');
        $loopBody = BasicBlockHelper::append($context, 'urlenc_write_body');
        $loopDone = BasicBlockHelper::append($context, 'urlenc_write_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $byte = $context->builder->load($context->builder->gep($charPtr, $idx));
        $pos = $context->builder->load($posSlot);
        $nextPos = self::writeEncodedByte($context, $byte, $destPtr, $pos, $hexTable, $formEncoding);
        $context->builder->store($nextPos, $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($afterBlock);

        return $loopDone;
    }

    private static function encodedByteLength(Context $context, Value $byte, bool $formEncoding): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $three = $i64->constInt(3, false);
        $unreserved = self::isUnreserved($context, $byte);
        $encodedLen = $context->builder->select($unreserved, $one, $three);

        if (!$formEncoding) {
            return $encodedLen;
        }

        $isSpace = $context->builder->icmp(
            Builder::INT_EQ,
            $byte,
            $context->getTypeFromString('int8')->constInt(32, false)
        );

        return $context->builder->select($isSpace, $one, $encodedLen);
    }

    private static function writeEncodedByte(
        Context $context,
        Value $byte,
        Value $destPtr,
        Value $pos,
        Value $hexTable,
        bool $formEncoding
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $percent = $context->getTypeFromString('int8')->constInt(37, false);
        $plus = $context->getTypeFromString('int8')->constInt(43, false);

        if ($formEncoding) {
            $isSpace = $context->builder->icmp(Builder::INT_EQ, $byte, $context->getTypeFromString('int8')->constInt(32, false));
            $spaceBlock = BasicBlockHelper::append($context, 'urlenc_write_space');
            $checkBlock = BasicBlockHelper::append($context, 'urlenc_write_check');
            $mergeBlock = BasicBlockHelper::append($context, 'urlenc_write_pos_merge');
            $context->builder->branchIf($isSpace, $spaceBlock, $checkBlock);

            $context->builder->positionAtEnd($spaceBlock);
            $context->builder->store($plus, $context->builder->gep($destPtr, $pos));
            $spaceNext = $context->builder->addNoSignedWrap($pos, $one);
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($checkBlock);
            $unreserved = self::isUnreserved($context, $byte);
            $plainBlock = BasicBlockHelper::append($context, 'urlenc_write_plain');
            $pctBlock = BasicBlockHelper::append($context, 'urlenc_write_pct');
            $context->builder->branchIf($unreserved, $plainBlock, $pctBlock);

            $context->builder->positionAtEnd($plainBlock);
            $context->builder->store($byte, $context->builder->gep($destPtr, $pos));
            $plainNext = $context->builder->addNoSignedWrap($pos, $one);
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($pctBlock);
            $pctNext = self::writePercentHex($context, $byte, $destPtr, $pos, $hexTable);
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($mergeBlock);
            $phi = $context->builder->phi($i64);
            $phi->addIncoming($spaceNext, $spaceBlock);
            $phi->addIncoming($plainNext, $plainBlock);
            $phi->addIncoming($pctNext, $pctBlock);

            return $phi;
        }

        $unreserved = self::isUnreserved($context, $byte);
        $plainBlock = BasicBlockHelper::append($context, 'urlenc_write_plain');
        $pctBlock = BasicBlockHelper::append($context, 'urlenc_write_pct');
        $mergeBlock = BasicBlockHelper::append($context, 'urlenc_write_pos_merge');
        $context->builder->branchIf($unreserved, $plainBlock, $pctBlock);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($byte, $context->builder->gep($destPtr, $pos));
        $plainNext = $context->builder->addNoSignedWrap($pos, $one);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($pctBlock);
        $pctNext = self::writePercentHex($context, $byte, $destPtr, $pos, $hexTable);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($plainNext, $plainBlock);
        $phi->addIncoming($pctNext, $pctBlock);

        return $phi;
    }

    private static function writePercentHex(
        Context $context,
        Value $byte,
        Value $destPtr,
        Value $pos,
        Value $hexTable
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $percent = $context->getTypeFromString('int8')->constInt(37, false);

        $context->builder->store($percent, $context->builder->gep($destPtr, $pos));
        $byteI32 = $context->builder->zExt($byte, $i32);
        $hi = $context->builder->lShr($byteI32, $i32->constInt(4, false));
        $lo = $context->builder->bitwiseAnd($byteI32, $i32->constInt(0x0F, false));
        $hiPos = $context->builder->addNoSignedWrap($pos, $one);
        $loPos = $context->builder->addNoSignedWrap($pos, $two);
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $hi)),
            $context->builder->gep($destPtr, $hiPos)
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $lo)),
            $context->builder->gep($destPtr, $loPos)
        );

        return $context->builder->addNoSignedWrap($pos, $three);
    }

    private static function isUnreserved(Context $context, Value $byte): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $digitLo = $context->builder->icmp(Builder::INT_UGE, $byte, $i8->constInt(48, false));
        $digitHi = $context->builder->icmp(Builder::INT_ULE, $byte, $i8->constInt(57, false));
        $isDigit = $context->builder->bitwiseAnd($digitLo, $digitHi);

        $upperLo = $context->builder->icmp(Builder::INT_UGE, $byte, $i8->constInt(65, false));
        $upperHi = $context->builder->icmp(Builder::INT_ULE, $byte, $i8->constInt(90, false));
        $isUpper = $context->builder->bitwiseAnd($upperLo, $upperHi);

        $lowerLo = $context->builder->icmp(Builder::INT_UGE, $byte, $i8->constInt(97, false));
        $lowerHi = $context->builder->icmp(Builder::INT_ULE, $byte, $i8->constInt(122, false));
        $isLower = $context->builder->bitwiseAnd($lowerLo, $lowerHi);

        $isDash = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(45, false));
        $isUnder = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(95, false));
        $isDot = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(46, false));
        $isTilde = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(126, false));

        return $context->builder->bitwiseOr(
            $context->builder->bitwiseOr(
                $context->builder->bitwiseOr($isDigit, $isUpper),
                $context->builder->bitwiseOr($isLower, $isDash)
            ),
            $context->builder->bitwiseOr(
                $context->builder->bitwiseOr($isUnder, $isDot),
                $isTilde
            )
        );
    }
}
