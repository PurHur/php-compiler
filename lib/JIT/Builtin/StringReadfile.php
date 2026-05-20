<?php

declare(strict_types=1);

/**
 * LLVM implementation of __compiler_readfile — stream a file to stdout via open/read/write.
 *
 * Returns total bytes written, or -1 when the path cannot be opened.
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

final class StringReadfile
{
    private const CHUNK = 8192;

    private const O_RDONLY = 0;

    private const STDOUT_FILENO = 1;

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_readfile');
        $entry = $fn->appendBasicBlock('rf_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $minusOne = $i64->constInt(-1, false);
        $chunkSize = $sizeT->constInt(self::CHUNK, false);
        $stdoutFd = $i32->constInt(self::STDOUT_FILENO, false);
        $oRdonly = $i32->constInt(self::O_RDONLY, false);

        $pathLen = $context->builder->load(
            $context->builder->structGep($path, $strMap['length'])
        );
        $pathBytes = $context->builder->structGep($path, $strMap['value']);
        $bufLen = $context->builder->add($pathLen, $oneI64);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $pathBuf = $context->builder->call($context->lookupFunction('malloc'), $bufLen);
            $pathCStr = $context->builder->pointerCast($pathBuf, $i8p);
        } else {
            $pathBuf = $context->builder->alloca($i8, $bufLen, 'readfile_path');
            $pathCStr = $context->builder->pointerCast($pathBuf, $i8p);
        }
        $context->intrinsic->memcpy($pathCStr, $pathBytes, $pathLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($pathCStr, $pathLen)
        );

        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $pathCStr,
            $oRdonly
        );
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('free'), $pathBuf);
        }

        $openFail = $context->builder->icmp(Builder::INT_SLT, $fd, $zeroI32);
        $failBlock = $fn->appendBasicBlock('rf_open_fail');
        $okBlock = $fn->appendBasicBlock('rf_open_ok');
        $context->builder->branchIf($openFail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($okBlock);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $chunkBuf = $context->builder->call(
                $context->lookupFunction('malloc'),
                $chunkSize
            );
            $chunkPtr = $context->builder->pointerCast($chunkBuf, $i8p);
        } else {
            $chunkBuf = $context->builder->alloca($i8, self::CHUNK, 'readfile_chunk');
            $chunkPtr = $context->builder->pointerCast($chunkBuf, $i8p);
        }

        $totalSlot = $context->builder->alloca($i64, 1, 'readfile_total');
        $context->builder->store($i64->constInt(0, false), $totalSlot);

        $loopHead = BasicBlockHelper::append($context, 'rf_loop_head');
        $loopBody = BasicBlockHelper::append($context, 'rf_loop_body');
        $loopDone = BasicBlockHelper::append($context, 'rf_loop_done');
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
        $nSizeT = $context->builder->truncOrBitCast($nRead, $sizeT);
        $nWritten = $context->builder->call(
            $context->lookupFunction('write'),
            $stdoutFd,
            $chunkPtr,
            $nSizeT
        );
        $writeFail = $context->builder->icmp(Builder::INT_SLT, $nWritten, $i64->constInt(0, false));
        $writeFailBlock = BasicBlockHelper::append($context, 'rf_write_fail');
        $writeOkBlock = BasicBlockHelper::append($context, 'rf_write_ok');
        $context->builder->branchIf($writeFail, $writeFailBlock, $writeOkBlock);

        $context->builder->positionAtEnd($writeFailBlock);
        $context->builder->call($context->lookupFunction('close'), $fd);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('free'), $chunkBuf);
        }
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($writeOkBlock);
        $total = $context->builder->load($totalSlot);
        $context->builder->store(
            $context->builder->add($total, $nWritten),
            $totalSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('close'), $fd);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('free'), $chunkBuf);
        }
        $context->builder->returnValue($context->builder->load($totalSlot));

        $context->builder->clearInsertionPosition();
    }
}
