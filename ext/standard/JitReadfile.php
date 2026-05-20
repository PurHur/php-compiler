<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helper for readfile() — fopen/fread loop, write(1) to stdout.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitReadfile
{
    private const CHUNK = 8192;

    private static int $blockSerial = 0;

    /** @return Value __value__* */
    public static function stream(Context $context, Value $strPtr): Value
    {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($strPtr, $map['value']);
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $zeroSize = $sizeT->constInt(0, false);
        $null = $i8p->constNull();

        $mode = $context->builder->pointerCast(
            $context->constantFromString('rb'),
            $charPtr
        );
        $fp = $context->builder->call(
            $context->lookupFunction('fopen'),
            $context->builder->pointerCast($pathPtr, $charPtr),
            $mode
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $fp, $null);

        $failBlock = BasicBlockHelper::append($context, 'readfile_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'readfile_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'readfile_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $slot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $slot);

        $context->builder->positionAtEnd($failBlock);
        self::writeBoolFalse($context, $outPtr);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $buf = $context->builder->alloca($i8, self::CHUNK, 'readfile_buf_'.$id);
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $totalSlot = $context->builder->alloca($i64, 1, 'readfile_total_'.$id);
        $context->builder->store($i64->constInt(0, false), $totalSlot);

        $loopHead = BasicBlockHelper::append($context, 'readfile_head_'.$id);
        $loopBody = BasicBlockHelper::append($context, 'readfile_body_'.$id);
        $loopDone = BasicBlockHelper::append($context, 'readfile_done_'.$id);
        $context->builder->branch($loopHead);

        $chunkConst = $sizeT->constInt(self::CHUNK, false);
        $one = $sizeT->constInt(1, false);
        $stdoutFd = $i32->constInt(1, false);

        $context->builder->positionAtEnd($loopHead);
        $nread = $context->builder->call(
            $context->lookupFunction('fread'),
            $bufPtr,
            $one,
            $chunkConst,
            $fp
        );
        $isEof = $context->builder->icmp(Builder::INT_EQ, $nread, $zeroSize);
        $context->builder->branchIf($isEof, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $context->builder->call(
            $context->lookupFunction('write'),
            $stdoutFd,
            $bufPtr,
            $nread
        );
        $prev = $context->builder->load($totalSlot);
        $added = $context->builder->zExt($nread, $i64);
        $context->builder->store($context->builder->add($prev, $added), $totalSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        JitValueBox::writeLong($context, $slot, $context->builder->load($totalSlot));
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $contBlock = BasicBlockHelper::append($context, 'readfile_cont_'.$id);
        $context->builder->branch($contBlock);
        $context->builder->positionAtEnd($contBlock);

        return $outPtr;
    }

    private static function writeBoolFalse(Context $context, Value $outPtr): void
    {
        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($outPtr, $valMap['type'])
        );
        $valueField = $context->builder->structGep($outPtr, $valMap['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $context->builder->store($i8->constInt(0, false), $firstByte);
    }
}
