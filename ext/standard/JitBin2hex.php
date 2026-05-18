<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for bin2hex() — byte string to lowercase hex (PHP-compatible).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitBin2hex
{
    public static function convert(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i32->constInt(2, false);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'bin2hex_empty');
        $workBlock = BasicBlockHelper::append($context, 'bin2hex_work');
        $doneBlock = BasicBlockHelper::append($context, 'bin2hex_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $lenI32 = $context->builder->truncOrBitCast($len, $i32);
        $outLen = $context->builder->mulNoSignedWrap($lenI32, $two);
        $outLenI64 = $context->builder->zExt($outLen, $i64);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLenI64);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store(
            $outLenI64,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $destPtr = $context->builder->structGep($dest, $destMap['value']);

        $hexTable = $context->builder->pointerCast(
            $context->constantFromString('0123456789abcdef'),
            $context->getTypeFromString('char*')
        );

        $idxSlot = $context->builder->alloca($i64, 1, 'bin2hex_idx');
        $context->builder->store($zero, $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'bin2hex_head');
        $loopBody = BasicBlockHelper::append($context, 'bin2hex_body');
        $loopDone = BasicBlockHelper::append($context, 'bin2hex_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $idxI32 = $context->builder->truncOrBitCast($idx, $i32);
        $byte = $context->builder->load($context->builder->gep($charPtr, $idx));
        $byteI32 = $context->builder->zExt($byte, $i32);
        $hi = $context->builder->logicalShiftRight($byteI32, $i32->constInt(4, false));
        $lo = $context->builder->bitwiseAnd(
            $byteI32,
            $i32->constInt(0x0F, false)
        );
        $outPos = $context->builder->mulNoSignedWrap($idxI32, $two);
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $hi)),
            $context->builder->gep($destPtr, $outPos)
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $lo)),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $i32->constInt(1, false)))
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $loopDone);

        return $result;
    }
}
