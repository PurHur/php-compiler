<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for gethostname() — /proc+/etc open/read (#28544, #29364).
 *
 * Used while NestedJIT compiles {@see GethostnameJitHelper} `@gethostname` (and any
 * NestedJIT call site) via {@see \PHPCompiler\JIT\Builtin\StringGethostname} —
 * kernel Internal removed (getenv #29313 shape).
 * Mirrors {@see VmHostPure} (no libc gethostname(2) — peer {@see JitRandomBytesKernel}).
 * Returns `__string__*` — empty string when unavailable (box to false at call site).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gethostname)
 */
final class JitGethostnameKernel
{
    /** php-src HOST_NAME_MAX; Linux basic_functions.c uses a 256-byte buffer. */
    private const BUF_SIZE = 256;

    private const O_RDONLY = 0;

    private const PROC_HOSTNAME = '/proc/sys/kernel/hostname';

    private const ETC_HOSTNAME = '/etc/hostname';

    /** @return Value `__string__*` — empty when unavailable */
    public static function invoke(Context $context): Value
    {
        LibcExtern::register($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $zeroI32 = $i32->constInt(0, false);
        $oRdonly = $i32->constInt(self::O_RDONLY, false);
        $bufSize = $i64->constInt(self::BUF_SIZE, false);

        $bufType = $i8->arrayType(self::BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'gethostname_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $bufPtr,
            $zeroI32,
            $bufSize
        );

        $failBb = $fn->appendBasicBlock('gethostname_kernel_fail');
        $okBb = $fn->appendBasicBlock('gethostname_kernel_ok');
        $doneBb = $fn->appendBasicBlock('gethostname_kernel_done');

        $procOk = self::tryReadHostnameFile(
            $context,
            $fn,
            self::PROC_HOSTNAME,
            $bufPtr,
            $oRdonly,
            $zeroI32
        );
        $afterProc = $context->builder->getInsertBlock();
        $tryEtcBb = $fn->appendBasicBlock('gethostname_kernel_try_etc');
        $context->builder->positionAtEnd($afterProc);
        $context->builder->branchIf($procOk, $okBb, $tryEtcBb);

        $context->builder->positionAtEnd($tryEtcBb);
        $etcOk = self::tryReadHostnameFile(
            $context,
            $fn,
            self::ETC_HOSTNAME,
            $bufPtr,
            $oRdonly,
            $zeroI32
        );
        $afterEtc = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($afterEtc);
        $context->builder->branchIf($etcOk, $okBb, $failBb);

        $context->builder->positionAtEnd($okBb);
        $len = self::trimmedLength($context, $bufPtr);
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('gethostname_kernel_empty');
        $initBb = $fn->appendBasicBlock('gethostname_kernel_init');
        $context->builder->branchIf($empty, $emptyBb, $initBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($initBb);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufPtr
        );
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($failBb);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtrTy, 'gethostname_kernel_result');
        $phi->addIncoming($owned, $okEnd);
        $phi->addIncoming($emptyStr, $failEnd);

        return $phi;
    }

    /** @return Value i1 — true when open+read produced at least one non-NUL byte */
    private static function tryReadHostnameFile(
        Context $context,
        Value $fn,
        string $path,
        Value $bufPtr,
        Value $oRdonly,
        Value $zeroI32
    ): Value {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $tag = str_replace(['/', '.'], '_', $path);
        $pathPtr = $context->builder->pointerCast(
            $context->constantFromString($path),
            $i8p
        );
        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $pathPtr,
            $oRdonly,
            $zeroI32
        );
        $openFail = $context->builder->icmp(Builder::INT_SLT, $fd, $zeroI32);
        $openFailBb = $fn->appendBasicBlock('gethostname_open_fail'.$tag);
        $readBb = $fn->appendBasicBlock('gethostname_read'.$tag);
        $doneBb = $fn->appendBasicBlock('gethostname_try_done'.$tag);
        $context->builder->branchIf($openFail, $openFailBb, $readBb);

        $context->builder->positionAtEnd($openFailBb);
        $falseOpen = $i1->constInt(0, false);
        $openFailEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($readBb);
        $nread = $context->builder->call(
            $context->lookupFunction('read'),
            $fd,
            $bufPtr,
            $i64->constInt(self::BUF_SIZE - 1, false)
        );
        $context->builder->call($context->lookupFunction('close'), $fd);
        $readOk = $context->builder->icmp(Builder::INT_SGT, $nread, $i64->constInt(0, false));
        $readEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i1, 'gethostname_try_ok'.$tag);
        $phi->addIncoming($falseOpen, $openFailEnd);
        $phi->addIncoming($readOk, $readEnd);

        return $phi;
    }

    /** Length of C string in $bufPtr with trailing CR/LF/space/tab stripped. */
    private static function trimmedLength(Context $context, Value $bufPtr): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();

        $rawLen = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);
        $lenI64 = $rawLen->typeOf() === $i64 ? $rawLen : $context->builder->zExt($rawLen, $i64);
        $lenSlot = $context->builder->alloca($i64, 1, 'gethostname_trim_len');
        $context->builder->store($lenI64, $lenSlot);

        $loopHead = $fn->appendBasicBlock('gethostname_trim_head');
        $loopBody = $fn->appendBasicBlock('gethostname_trim_body');
        $loopDone = $fn->appendBasicBlock('gethostname_trim_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $cur = $context->builder->load($lenSlot);
        $nonzero = $context->builder->icmp(Builder::INT_SGT, $cur, $i64->constInt(0, false));
        $context->builder->branchIf($nonzero, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $idx = $context->builder->sub($cur, $i64->constInt(1, false));
        $bytePtr = $context->builder->inBoundsGep($bufPtr, $idx);
        $byte = $context->builder->load($bytePtr);
        $isNl = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(0x0a, false));
        $isCr = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(0x0d, false));
        $isSp = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(0x20, false));
        $isTab = $context->builder->icmp(Builder::INT_EQ, $byte, $i8->constInt(0x09, false));
        $ws = $context->builder->or($context->builder->or($isNl, $isCr), $context->builder->or($isSp, $isTab));
        $keepBb = $fn->appendBasicBlock('gethostname_trim_keep');
        $shrinkBb = $fn->appendBasicBlock('gethostname_trim_shrink');
        $context->builder->branchIf($ws, $shrinkBb, $keepBb);

        $context->builder->positionAtEnd($shrinkBb);
        $context->builder->store($i8->constInt(0, false), $bytePtr);
        $context->builder->store($idx, $lenSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($keepBb);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);

        return $context->builder->load($lenSlot);
    }
}
