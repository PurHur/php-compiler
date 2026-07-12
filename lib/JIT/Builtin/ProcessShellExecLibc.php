<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;

/**
 * libc popen/fread shell_exec for user-script AOT (#10492, #15407).
 *
 * Nested {@see ProcessJitHelper} uses VmPopenPure/proc_open, which is absent in
 * standalone AOT binaries; this path matches {@see StringFileGetContentsLibc}.
 * php-src: ext/standard/exec.c — PHP_FUNCTION(shell_exec)
 */
final class ProcessShellExecLibc
{
    private const ABI = '__compiler_shell_exec';

    private const CHUNK = 8192;

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::register($context);

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction(self::ABI);
        $entry = $fn->appendBasicBlock('pse_libc_entry');
        $context->builder->positionAtEnd($entry);

        $cmd = $fn->getParam(0);
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $oneI64 = $i64->constInt(1, false);
        $chunkSize = $sizeT->constInt(self::CHUNK, false);
        $nullStr = $strPtr->constNull();

        $cmdNull = $context->builder->icmp(Builder::INT_EQ, $cmd, $nullStr);
        $cmdNullBb = $fn->appendBasicBlock('pse_libc_cmd_null');
        $cmdOkBb = $fn->appendBasicBlock('pse_libc_cmd_ok');
        $context->builder->branchIf($cmdNull, $cmdNullBb, $cmdOkBb);
        $context->builder->positionAtEnd($cmdNullBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($cmdOkBb);
        $cmdLen = $context->builder->load($context->builder->structGep($cmd, $strMap['length']));
        $cmdBytes = $context->builder->structGep($cmd, $strMap['value']);
        $bufLen = $context->builder->add($cmdLen, $oneI64);
        $cmdBuf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufLen);
        $cmdCStr = $context->builder->pointerCast($cmdBuf, $i8p);
        $context->intrinsic->memcpy($cmdCStr, $cmdBytes, $cmdLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($cmdCStr, $cmdLen)
        );

        $modePtr = $context->builder->pointerCast($context->constantFromString('r'), $i8p);
        $pipe = $context->builder->call($context->lookupFunction('popen'), $cmdCStr, $modePtr);
        $context->builder->call($context->lookupFunction('__mm__free'), $cmdBuf);

        $openFail = $context->builder->icmp(Builder::INT_EQ, $pipe, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('pse_libc_popen_fail');
        $readBb = $fn->appendBasicBlock('pse_libc_read');
        $context->builder->branchIf($openFail, $failBb, $readBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($readBb);
        $initialBuf = $context->builder->call($context->lookupFunction('__mm__malloc'), $chunkSize);
        $dataBufSlot = $context->builder->alloca($i8p, 1, 'pse_data_buf');
        $context->builder->store($initialBuf, $dataBufSlot);
        $chunkBuf = $context->builder->call($context->lookupFunction('__mm__malloc'), $chunkSize);
        $chunkPtr = $context->builder->pointerCast($chunkBuf, $i8p);
        $sizeSlot = $context->builder->alloca($i64, 1, 'pse_size');
        $capSlot = $context->builder->alloca($i64, 1, 'pse_cap');
        $chunkI64 = $context->builder->zExt($chunkSize, $i64);
        $context->builder->store($i64->constInt(0, false), $sizeSlot);
        $context->builder->store($chunkI64, $capSlot);

        $loopHead = BasicBlockHelper::append($context, 'pse_loop_head');
        $loopBody = BasicBlockHelper::append($context, 'pse_loop_body');
        $loopDone = BasicBlockHelper::append($context, 'pse_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $nRead = $context->builder->call(
            $context->lookupFunction('fread'),
            $chunkPtr,
            $sizeT->constInt(1, false),
            $chunkSize,
            $pipe
        );
        $noMore = $context->builder->icmp(Builder::INT_EQ, $nRead, $sizeT->constInt(0, false));
        $context->builder->branchIf($noMore, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $size = $context->builder->load($sizeSlot);
        $cap = $context->builder->load($capSlot);
        $nReadI64 = $context->builder->zExt($nRead, $i64);
        $needed = $context->builder->add($size, $nReadI64);
        $needGrow = $context->builder->icmp(Builder::INT_SGT, $needed, $cap);
        $growBb = BasicBlockHelper::append($context, 'pse_grow');
        $appendBb = BasicBlockHelper::append($context, 'pse_append');
        $context->builder->branchIf($needGrow, $growBb, $appendBb);

        $context->builder->positionAtEnd($growBb);
        $doubled = $context->builder->mul($cap, $i64->constInt(2, false));
        $newCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $doubled, $needed),
            $doubled,
            $needed
        );
        $grown = $context->builder->call(
            $context->lookupFunction('__mm__realloc'),
            $context->builder->load($dataBufSlot),
            $context->builder->truncOrBitCast($newCap, $sizeT)
        );
        $grownNull = $context->builder->icmp(Builder::INT_EQ, $grown, $i8p->constNull());
        $growFail = BasicBlockHelper::append($context, 'pse_grow_fail');
        $growOk = BasicBlockHelper::append($context, 'pse_grow_ok');
        $context->builder->branchIf($grownNull, $growFail, $growOk);
        $context->builder->positionAtEnd($growFail);
        $context->builder->call($context->lookupFunction('pclose'), $pipe);
        $context->builder->call($context->lookupFunction('__mm__free'), $context->builder->load($dataBufSlot));
        $context->builder->call($context->lookupFunction('__mm__free'), $chunkBuf);
        $context->builder->returnValue($nullStr);
        $context->builder->positionAtEnd($growOk);
        $context->builder->store($grown, $dataBufSlot);
        $context->builder->store($newCap, $capSlot);
        $context->builder->branch($appendBb);

        $context->builder->positionAtEnd($appendBb);
        $size = $context->builder->load($sizeSlot);
        $dataPtr = $context->builder->pointerCast($context->builder->load($dataBufSlot), $i8p);
        $destAt = $context->builder->gep($dataPtr, $size);
        $context->intrinsic->memcpy($destAt, $chunkPtr, $nRead, false);
        $context->builder->store($context->builder->add($size, $nReadI64), $sizeSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('pclose'), $pipe);
        $context->builder->call($context->lookupFunction('__mm__free'), $chunkBuf);
        $finalSize = $context->builder->load($sizeSlot);
        $empty = $context->builder->icmp(Builder::INT_EQ, $finalSize, $i64->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('pse_empty');
        $okBb = $fn->appendBasicBlock('pse_ok');
        $context->builder->branchIf($empty, $emptyBb, $okBb);
        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call($context->lookupFunction('__mm__free'), $context->builder->load($dataBufSlot));
        $context->builder->returnValue($nullStr);
        $context->builder->positionAtEnd($okBb);
        $dataPtr = $context->builder->pointerCast($context->builder->load($dataBufSlot), $i8p);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalSize,
            $dataPtr
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $context->builder->load($dataBufSlot));
        $context->builder->returnValue($result);

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
