<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for user-script AOT file_get_contents — thin libc open/read (#19279).
 *
 * Nested {@see FileGetContentsJitHelper} does not run under minimal standalone init
 * (#16075); this kernel mirrors pre-#15309 LLVM from ext/ not lib/JIT/Builtin/.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_mem
 */
final class JitFileGetContentsKernel
{
    private const CHUNK = 8192;

    private const O_RDONLY = 0;

    /** Emit libc read loop; builder must be positioned at the bridge entry block. */
    public static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $path = $fn->getParam(0);
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $chunkSize = $sizeT->constInt(self::CHUNK, false);
        $oRdonly = $i32->constInt(self::O_RDONLY, false);
        $nullStr = $strPtr->constNull();

        $pathLen = $context->builder->load(
            $context->builder->structGep($path, $strMap['length'])
        );
        $pathBytes = $context->builder->structGep($path, $strMap['value']);
        $bufLen = $context->builder->add($pathLen, $oneI64);
        $pathBuf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufLen);
        $pathCStr = $context->builder->pointerCast($pathBuf, $i8p);
        $context->intrinsic->memcpy($pathCStr, $pathBytes, $pathLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($pathCStr, $pathLen)
        );

        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $pathCStr,
            $oRdonly,
            $zeroI32
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $pathBuf);

        $openFail = $context->builder->icmp(Builder::INT_SLT, $fd, $zeroI32);
        $failBlock = $fn->appendBasicBlock('fgc_kernel_open_fail');
        $okBlock = $fn->appendBasicBlock('fgc_kernel_open_ok');
        $context->builder->branchIf($openFail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okBlock);
        $initialBuf = $context->builder->call($context->lookupFunction('__mm__malloc'), $chunkSize);
        $dataBufSlot = $context->builder->alloca($i8p, 1, 'fgc_kernel_data_buf');
        $context->builder->store($initialBuf, $dataBufSlot);

        $chunkBuf = $context->builder->call($context->lookupFunction('__mm__malloc'), $chunkSize);
        $chunkPtr = $context->builder->pointerCast($chunkBuf, $i8p);

        $sizeSlot = $context->builder->alloca($i64, 1, 'fgc_kernel_size');
        $capSlot = $context->builder->alloca($i64, 1, 'fgc_kernel_cap');
        $chunkI64 = $context->builder->zExt($chunkSize, $i64);
        $context->builder->store($i64->constInt(0, false), $sizeSlot);
        $context->builder->store($chunkI64, $capSlot);

        $loopHead = BasicBlockHelper::append($context, 'fgc_kernel_loop_head');
        $loopBody = BasicBlockHelper::append($context, 'fgc_kernel_loop_body');
        $loopDone = BasicBlockHelper::append($context, 'fgc_kernel_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $nRead = $context->builder->call(
            $context->lookupFunction('read'),
            $fd,
            $chunkPtr,
            $chunkSize
        );
        $noMore = $context->builder->icmp(Builder::INT_SLE, $nRead, $i64->constInt(0, false));
        $context->builder->branchIf($noMore, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $readErr = $context->builder->icmp(Builder::INT_SLT, $nRead, $i64->constInt(0, false));
        $readErrBlock = BasicBlockHelper::append($context, 'fgc_kernel_read_err');
        $readOkBlock = BasicBlockHelper::append($context, 'fgc_kernel_read_ok');
        $context->builder->branchIf($readErr, $readErrBlock, $readOkBlock);

        $context->builder->positionAtEnd($readErrBlock);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call(
            $context->lookupFunction('__mm__free'),
            $context->builder->load($dataBufSlot)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $chunkBuf);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($readOkBlock);
        $size = $context->builder->load($sizeSlot);
        $cap = $context->builder->load($capSlot);
        $needed = $context->builder->add($size, $nRead);
        $needGrow = $context->builder->icmp(Builder::INT_SGT, $needed, $cap);
        $growBlock = BasicBlockHelper::append($context, 'fgc_kernel_grow');
        $appendBlock = BasicBlockHelper::append($context, 'fgc_kernel_append');
        $context->builder->branchIf($needGrow, $growBlock, $appendBlock);

        $context->builder->positionAtEnd($growBlock);
        $doubled = $context->builder->mul($cap, $i64->constInt(2, false));
        $newCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $doubled, $needed),
            $doubled,
            $needed
        );
        $newCapSizeT = $context->builder->truncOrBitCast($newCap, $sizeT);
        $grown = $context->builder->call(
            $context->lookupFunction('__mm__realloc'),
            $context->builder->load($dataBufSlot),
            $newCapSizeT
        );
        $grownNull = $context->builder->icmp(Builder::INT_EQ, $grown, $i8p->constNull());
        $reallocFailBlock = BasicBlockHelper::append($context, 'fgc_kernel_realloc_fail');
        $reallocOkBlock = BasicBlockHelper::append($context, 'fgc_kernel_realloc_ok');
        $context->builder->branchIf($grownNull, $reallocFailBlock, $reallocOkBlock);

        $context->builder->positionAtEnd($reallocFailBlock);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call(
            $context->lookupFunction('__mm__free'),
            $context->builder->load($dataBufSlot)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $chunkBuf);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($reallocOkBlock);
        $context->builder->store($grown, $dataBufSlot);
        $context->builder->store($newCap, $capSlot);
        $context->builder->branch($appendBlock);

        $context->builder->positionAtEnd($appendBlock);
        $size = $context->builder->load($sizeSlot);
        $dataPtr = $context->builder->pointerCast($context->builder->load($dataBufSlot), $i8p);
        $destAt = $context->builder->gep($dataPtr, $size);
        $nSizeT = $context->builder->truncOrBitCast($nRead, $sizeT);
        $context->intrinsic->memcpy($destAt, $chunkPtr, $nSizeT, false);
        $context->builder->store(
            $context->builder->add($size, $nRead),
            $sizeSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call($context->lookupFunction('__mm__free'), $chunkBuf);

        $finalSize = $context->builder->load($sizeSlot);
        $dataPtr = $context->builder->pointerCast($context->builder->load($dataBufSlot), $i8p);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalSize,
            $dataPtr
        );
        $context->builder->call(
            $context->lookupFunction('__mm__free'),
            $context->builder->load($dataBufSlot)
        );
        $context->builder->returnValue($result);
    }
}
