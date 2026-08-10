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
 * LLVM NestedJIT leaf for readfile() — thin libc open/read/write (#19966, #29915).
 *
 * Used while NestedJIT compiles {@see ReadfileJitHelper} `@readfile` so the
 * helper does not re-enter `__compiler_readfile` (file_get_contents #29833 shape).
 * Peer: {@see JitFileGetContentsLibc} (#29833).
 * php-src: ext/standard/streamsfuncs.c — php_stream_passthru
 */
final class JitReadfileLibc
{
    private const ABI = '__phpc_readfile_libc';

    private const BRIDGE_ENTRY = 'rf_libc_entry';

    private const CHUNK = 8192;

    private const O_RDONLY = 0;

    private const STDOUT_FILENO = 1;

    /** @return Value i64 — bytes written to stdout, or -1 when open/read/write fails */
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
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i64, false, $strPtr)
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

    /** Emit libc passthru loop; builder must be positioned at the bridge entry block. */
    public static function emitBody(Context $context, LlvmFunction $fn): void
    {
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
        $failBlock = $fn->appendBasicBlock('rf_libc_open_fail');
        $okBlock = $fn->appendBasicBlock('rf_libc_open_ok');
        $context->builder->branchIf($openFail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($okBlock);
        $chunkBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $chunkSize
        );
        $chunkPtr = $context->builder->pointerCast($chunkBuf, $i8p);

        $totalSlot = $context->builder->alloca($i64, 1, 'rf_libc_total');
        $context->builder->store($i64->constInt(0, false), $totalSlot);

        $loopHead = BasicBlockHelper::append($context, 'rf_libc_loop_head');
        $loopBody = BasicBlockHelper::append($context, 'rf_libc_loop_body');
        $loopDone = BasicBlockHelper::append($context, 'rf_libc_loop_done');
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
        $nWrittenAsRead = $context->builder->truncOrBitCast($nWritten, $i64);
        $writeFail = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $nWritten, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $nWrittenAsRead, $nRead)
        );
        $writeFailBlock = BasicBlockHelper::append($context, 'rf_libc_write_fail');
        $writeOkBlock = BasicBlockHelper::append($context, 'rf_libc_write_ok');
        $context->builder->branchIf($writeFail, $writeFailBlock, $writeOkBlock);

        $context->builder->positionAtEnd($writeFailBlock);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call($context->lookupFunction('__mm__free'), $chunkBuf);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($writeOkBlock);
        $total = $context->builder->load($totalSlot);
        $context->builder->store(
            $context->builder->add($total, $nRead),
            $totalSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->call($context->lookupFunction('__mm__free'), $chunkBuf);
        $context->builder->returnValue($context->builder->load($totalSlot));
    }
}
