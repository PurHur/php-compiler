<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM CRC32/CRC32C compute without C runtime (__compiler_crc32*).
 *
 * php-src: ext/standard/crc32.c (CRC32B), ext/standard/hash_crc32.c (CRC32C).
 */
final class JitCrcCore
{
    public const POLY_CRC32 = 0xEDB88320;
    public const POLY_CRC32C = 0x82F63B78;

    public static function computeCrc32(Context $context, Value $subject, Value $seed): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $seed32 = $context->builder->trunc($seed, $i32);
        $stateInit = $context->builder->xor(
            $seed32,
            $i32->constInt(0xFFFFFFFF, false)
        );

        return self::computeBytes($context, $subject, $stateInit, self::POLY_CRC32);
    }

    public static function computeCrc32c(Context $context, Value $subject): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return self::computeBytes(
            $context,
            $subject,
            $i32->constInt(0xFFFFFFFF, false),
            self::POLY_CRC32C
        );
    }

    private static function computeBytes(
        Context $context,
        Value $subject,
        Value $stateInit,
        int $poly
    ): Value {
        $structName = $subject->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($subject, $map['length'])
        );
        $charPtr = $context->builder->structGep($subject, $map['value']);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $mask8 = $i32->constInt(0xFF, false);
        $mask32 = $i32->constInt(0xFFFFFFFF, false);

        $tableSlot = $context->builder->alloca($i32->arrayType(256), 1, 'crc_table');
        self::buildTableOnStack($context, $tableSlot, $poly);

        $stateSlot = $context->builder->alloca($i32, 1, 'crc_state');
        $context->builder->store($stateInit, $stateSlot);

        $idxSlot = $context->builder->alloca($i64, 1, 'crc_i');
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'crc_byte_head');
        $body = BasicBlockHelper::append($context, 'crc_byte_body');
        $done = BasicBlockHelper::append($context, 'crc_byte_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $state = $context->builder->load($stateSlot);
        $byte = $context->builder->zext(
            $context->builder->load($context->builder->gep($charPtr, $idx)),
            $i32
        );
        $tblIdx = $context->builder->and(
            $context->builder->xor($state, $byte),
            $mask8
        );
        $tblIdx64 = $context->builder->zext($tblIdx, $i64);
        $tblPtr = $context->builder->inBoundsGEP($tableSlot, $zero, $tblIdx64);
        $entry = $context->builder->load($tblPtr);
        $nextState = $context->builder->xor(
            $context->builder->lshr($state, $i32->constInt(8, false)),
            $entry
        );
        $context->builder->store($nextState, $stateSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $finalState = $context->builder->and(
            $context->builder->xor(
                $context->builder->load($stateSlot),
                $mask32
            ),
            $mask32
        );

        return $context->builder->sext($finalState, $i64);
    }

    private static function buildTableOnStack(Context $context, Value $tableSlot, int $poly): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $eight = $i64->constInt(8, false);
        $two56 = $i64->constInt(256, false);
        $polyConst = $i32->constInt($poly, false);
        $one32 = $i32->constInt(1, false);

        $iSlot = $context->builder->alloca($i64, 1, 'crc_tbl_i');
        $jSlot = $context->builder->alloca($i64, 1, 'crc_tbl_j');
        $cSlot = $context->builder->alloca($i32, 1, 'crc_tbl_c');
        $context->builder->store($zero, $iSlot);

        $outerHead = BasicBlockHelper::append($context, 'crc_tbl_outer_head');
        $outerBody = BasicBlockHelper::append($context, 'crc_tbl_outer_body');
        $outerDone = BasicBlockHelper::append($context, 'crc_tbl_outer_done');
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerHead);
        $iVal = $context->builder->load($iSlot);
        $outerStop = $context->builder->icmp(Builder::INT_SGE, $iVal, $two56);
        $context->builder->branchIf($outerStop, $outerDone, $outerBody);

        $context->builder->positionAtEnd($outerBody);
        $context->builder->store(
            $context->builder->trunc($iVal, $i32),
            $cSlot
        );
        $context->builder->store($zero, $jSlot);

        $innerHead = BasicBlockHelper::append($context, 'crc_tbl_inner_head');
        $innerBody = BasicBlockHelper::append($context, 'crc_tbl_inner_body');
        $innerDone = BasicBlockHelper::append($context, 'crc_tbl_inner_done');
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerHead);
        $jVal = $context->builder->load($jSlot);
        $innerStop = $context->builder->icmp(Builder::INT_SGE, $jVal, $eight);
        $context->builder->branchIf($innerStop, $innerDone, $innerBody);

        $context->builder->positionAtEnd($innerBody);
        $cVal = $context->builder->load($cSlot);
        $lowBit = $context->builder->and($cVal, $one32);
        $isOne = $context->builder->icmp(Builder::INT_NE, $lowBit, $i32->constInt(0, false));
        $shiftBlock = BasicBlockHelper::append($context, 'crc_tbl_shift');
        $xorBlock = BasicBlockHelper::append($context, 'crc_tbl_xor');
        $mergeBlock = BasicBlockHelper::append($context, 'crc_tbl_merge');
        $context->builder->branchIf($isOne, $xorBlock, $shiftBlock);

        $context->builder->positionAtEnd($shiftBlock);
        $shifted = $context->builder->lshr($cVal, $one32);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($xorBlock);
        $xored = $context->builder->xor(
            $polyConst,
            $context->builder->lshr($cVal, $one32)
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $nextC = $context->builder->phi($i32);
        $nextC->addIncoming($shifted, $shiftBlock);
        $nextC->addIncoming($xored, $xorBlock);
        $context->builder->store($nextC, $cSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($jVal, $one),
            $jSlot
        );
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerDone);
        $tblPtr = $context->builder->inBoundsGEP($tableSlot, $zero, $iVal);
        $context->builder->store($context->builder->load($cSlot), $tblPtr);
        $context->builder->store(
            $context->builder->addNoSignedWrap($iVal, $one),
            $iSlot
        );
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerDone);
    }
}
