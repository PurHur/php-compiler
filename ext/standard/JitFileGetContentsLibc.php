<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM NestedJIT leaf for file_get_contents() — thin libc open/read (#26756, #29833).
 *
 * Used while NestedJIT compiles {@see FileGetContentsJitHelper} `@file_get_contents` so the
 * helper does not re-enter `__compiler_file_get_contents` (crypt #29545 / random_bytes #29531).
 * Peer: {@see JitReadfileLibc} (#19966).
 * php-src: ext/standard/file.c — php_stream_copy_to_mem
 */
final class JitFileGetContentsLibc
{
    private const ABI = '__phpc_file_get_contents_libc';

    private const BRIDGE_ENTRY = 'fgc_libc_entry';

    private const CHUNK = 8192;

    private const O_RDONLY = 0;

    /** @return Value `__string__*` — file bytes, or null when open/read fails */
    public static function call(Context $context, Value $path): Value
    {
        self::ensureLibcFunction($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
    }

    private static function ensureLibcFunction(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        LibcExtern::register($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        self::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Emit libc read→__string__; builder must be positioned at the bridge entry block. */
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
        $zeroI64 = $i64->constInt(0, false);
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
        $failBlock = $fn->appendBasicBlock('fgc_libc_open_fail');
        $okBlock = $fn->appendBasicBlock('fgc_libc_open_ok');
        $context->builder->branchIf($openFail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okBlock);
        $capSlot = $context->builder->alloca($i64, 1, 'fgc_libc_cap');
        $totalSlot = $context->builder->alloca($i64, 1, 'fgc_libc_total');
        $bufSlot = $context->builder->alloca($i8p, 1, 'fgc_libc_buf');
        $context->builder->store($i64->constInt(self::CHUNK, false), $capSlot);
        $context->builder->store($zeroI64, $totalSlot);
        $initBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $chunkSize
        );
        $context->builder->store(
            $context->builder->pointerCast($initBuf, $i8p),
            $bufSlot
        );

        $loopHead = BasicBlockHelper::append($context, 'fgc_libc_loop_head');
        $loopBody = BasicBlockHelper::append($context, 'fgc_libc_loop_body');
        $loopGrow = BasicBlockHelper::append($context, 'fgc_libc_loop_grow');
        $loopRead = BasicBlockHelper::append($context, 'fgc_libc_loop_read');
        $loopDone = BasicBlockHelper::append($context, 'fgc_libc_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $total = $context->builder->load($totalSlot);
        $cap = $context->builder->load($capSlot);
        $needGrow = $context->builder->icmp(Builder::INT_SGE, $total, $cap);
        $context->builder->branchIf($needGrow, $loopGrow, $loopRead);

        $context->builder->positionAtEnd($loopGrow);
        $newCap = $context->builder->mul($cap, $i64->constInt(2, false));
        $context->builder->store($newCap, $capSlot);
        $oldBuf = $context->builder->load($bufSlot);
        $newBuf = $context->builder->call(
            $context->lookupFunction('__mm__realloc'),
            $oldBuf,
            $context->builder->truncOrBitCast($newCap, $sizeT)
        );
        $reallocFail = $context->builder->icmp(Builder::INT_EQ, $newBuf, $i8p->constNull());
        $reallocFailBb = BasicBlockHelper::append($context, 'fgc_libc_realloc_fail');
        $reallocOkBb = BasicBlockHelper::append($context, 'fgc_libc_realloc_ok');
        $context->builder->branchIf($reallocFail, $reallocFailBb, $reallocOkBb);

        $context->builder->positionAtEnd($reallocFailBb);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call($context->lookupFunction('__mm__free'), $oldBuf);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($reallocOkBb);
        $context->builder->store($newBuf, $bufSlot);
        $context->builder->branch($loopRead);

        $context->builder->positionAtEnd($loopRead);
        $buf = $context->builder->load($bufSlot);
        $total2 = $context->builder->load($totalSlot);
        $cap2 = $context->builder->load($capSlot);
        $space = $context->builder->sub($cap2, $total2);
        $dest = $context->builder->inBoundsGEP($buf, $total2);
        $nRead = $context->builder->call(
            $context->lookupFunction('read'),
            $fd,
            $dest,
            $space
        );
        $noMore = $context->builder->icmp(Builder::INT_SLE, $nRead, $zeroI64);
        $context->builder->branchIf($noMore, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $context->builder->store(
            $context->builder->add($context->builder->load($totalSlot), $nRead),
            $totalSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $finalBuf = $context->builder->load($bufSlot);
        $finalLen = $context->builder->load($totalSlot);
        $phpcStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalLen,
            $finalBuf
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $finalBuf);
        $context->builder->returnValue($phpcStr);
    }
}
