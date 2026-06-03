<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_gethostbynamel (issue #5299, #3707).
 *
 * Mirrors ext/standard/VmDns::resolveViaGetaddrinfo() and phpc_gethostbynamel.c.
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbynamel)
 */
final class GethostbynamelRuntime
{
    private const AF_INET = 2;

    private const SOCK_STREAM = 1;

    private const MAX_ADDRS = 64;

    private const HOSTBUF_LEN = 256;

    private const IPBUF_LEN = 16;

    /** Linux x86_64 glibc struct addrinfo size and field offsets. */
    private const ADDRINFO_SIZE = 48;

    private const ADDRINFO_OFF_FAMILY = 4;

    private const ADDRINFO_OFF_SOCKTYPE = 8;

    private const ADDRINFO_OFF_ADDR = 24;

    private const ADDRINFO_OFF_NEXT = 40;

    /** struct sockaddr_in::sin_addr offset. */
    private const SOCKADDR_IN_OFF_SIN_ADDR = 4;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_gethostbynamel');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_gethostbynamel', $ft);
        self::implementGethostbynamel($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementGethostbynamel(Context $context, Value $fn): void
    {
        self::ensureLibcDns($context);

        $entry = $fn->appendBasicBlock('ghbl_entry');
        $context->builder->positionAtEnd($entry);

        $hostname = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);

        $nullHost = $context->builder->icmp(Builder::INT_EQ, $hostname, $hostname->typeOf()->constNull());
        $invalidBb = $fn->appendBasicBlock('ghbl_invalid');
        $copyBb = $fn->appendBasicBlock('ghbl_copy');
        $context->builder->branchIf($nullHost, $invalidBb, $copyBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($copyBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($hostname, $map['length']));
        $lenOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $len, $zeroI64),
            $context->builder->icmp(Builder::INT_SLT, $len, $i64->constInt(self::HOSTBUF_LEN, false))
        );
        $lenFailBb = $fn->appendBasicBlock('ghbl_len_fail');
        $lenOkBb = $fn->appendBasicBlock('ghbl_len_ok');
        $context->builder->branchIf($lenOk, $lenOkBb, $lenFailBb);

        $context->builder->positionAtEnd($lenFailBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($lenOkBb);
        $hostbuf = $context->builder->alloca($i8, self::HOSTBUF_LEN, 'ghbl_host');
        $valPtr = $context->builder->structGep($hostname, $map['value']);
        $src = $context->builder->pointerCast($valPtr, $i8p);
        $len32 = $context->builder->trunc($len, $i32);
        $hostbufVoid = $context->builder->pointerCast($hostbuf, $voidPtr);
        $srcVoid = $context->builder->pointerCast($src, $voidPtr);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $hostbufVoid,
            $srcVoid,
            $context->builder->zExt($len32, $sizeT)
        );
        $nulIdx = $context->builder->gep($hostbuf, $len32);
        $context->builder->store($i8->constInt(0, false), $nulIdx);

        $hints = $context->builder->alloca($i8, self::ADDRINFO_SIZE, 'ghbl_hints');
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->builder->pointerCast($hints, $voidPtr),
            $i32->constInt(0, false),
            $sizeT->constInt(self::ADDRINFO_SIZE, false)
        );
        $hintsI32 = $context->builder->pointerCast($hints, $i32->pointerType(0));
        $familyPtr = $context->builder->gep($hintsI32, $i32->constInt(self::ADDRINFO_OFF_FAMILY / 4, false));
        $sockPtr = $context->builder->gep($hintsI32, $i32->constInt(self::ADDRINFO_OFF_SOCKTYPE / 4, false));
        $context->builder->store($i32->constInt(self::AF_INET, false), $familyPtr);
        $context->builder->store($i32->constInt(self::SOCK_STREAM, false), $sockPtr);

        $resSlot = $context->builder->alloca($voidPtr, 1, 'ghbl_res');
        $context->builder->store($voidPtr->constNull(), $resSlot);
        $hintsVoid = $context->builder->pointerCast($hints, $voidPtr);
        $rc = $context->builder->call(
            $context->lookupFunction('getaddrinfo'),
            $hostbuf,
            $voidPtr->constNull(),
            $hintsVoid,
            $resSlot
        );
        $gaFailBb = $fn->appendBasicBlock('ghbl_ga_fail');
        $loopInitBb = $fn->appendBasicBlock('ghbl_loop_init');
        $gaOk = $context->builder->icmp(Builder::INT_EQ, $rc, $zeroI32);
        $context->builder->branchIf($gaOk, $loopInitBb, $gaFailBb);

        $context->builder->positionAtEnd($gaFailBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($loopInitBb);
        $countSlot = $context->builder->alloca($sizeT, 1, 'ghbl_count');
        $context->builder->store($sizeT->constInt(0, false), $countSlot);
        $storedBase = $context->builder->alloca(
            $i8,
            self::MAX_ADDRS * self::IPBUF_LEN,
            'ghbl_stored'
        );
        $rpSlot = $context->builder->alloca($voidPtr, 1, 'ghbl_rp');
        $context->builder->store($context->builder->load($resSlot), $rpSlot);
        $loopHeadBb = $fn->appendBasicBlock('ghbl_loop_head');
        $context->builder->branch($loopHeadBb);

        $context->builder->positionAtEnd($loopHeadBb);
        $rp = $context->builder->load($rpSlot);
        $rpDone = $context->builder->icmp(Builder::INT_EQ, $rp, $voidPtr->constNull());
        $loopDoneBb = $fn->appendBasicBlock('ghbl_loop_done');
        $loopBodyBb = $fn->appendBasicBlock('ghbl_loop_body');
        $context->builder->branchIf($rpDone, $loopDoneBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $rpBytes = $context->builder->pointerCast($rp, $i8p);
        $rpI32 = $context->builder->pointerCast($rp, $i32->pointerType(0));
        $famPtr = $context->builder->gep($rpI32, $i32->constInt(self::ADDRINFO_OFF_FAMILY / 4, false));
        $family = $context->builder->load($famPtr);
        $famInet = $context->builder->icmp(Builder::INT_EQ, $family, $i32->constInt(self::AF_INET, false));
        $nextRpBb = $fn->appendBasicBlock('ghbl_next_rp');
        $hasAddrBb = $fn->appendBasicBlock('ghbl_has_addr');
        $context->builder->branchIf($famInet, $hasAddrBb, $nextRpBb);

        $context->builder->positionAtEnd($hasAddrBb);
        $addrFieldPtr = $context->builder->gep($rpBytes, $sizeT->constInt(self::ADDRINFO_OFF_ADDR, false));
        $aiAddr = $context->builder->load(
            $context->builder->pointerCast($addrFieldPtr, $voidPtr->pointerType(0))
        );
        $noAddrBb = $fn->appendBasicBlock('ghbl_no_addr');
        $inetBb = $fn->appendBasicBlock('ghbl_inet');
        $hasAddr = $context->builder->icmp(Builder::INT_NE, $aiAddr, $voidPtr->constNull());
        $context->builder->branchIf($hasAddr, $inetBb, $noAddrBb);

        $context->builder->positionAtEnd($noAddrBb);
        $context->builder->branch($nextRpBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($inetBb);
        $ipbuf = $context->builder->alloca($i8, self::IPBUF_LEN, 'ghbl_ip');
        $sinAddr = $context->builder->gep(
            $context->builder->pointerCast($aiAddr, $i8p),
            $i32->constInt(self::SOCKADDR_IN_OFF_SIN_ADDR, false)
        );
        $ntop = $context->builder->call(
            $context->lookupFunction('inet_ntop'),
            $i32->constInt(self::AF_INET, false),
            $context->builder->pointerCast($sinAddr, $voidPtr),
            $ipbuf,
            $sizeT->constInt(self::IPBUF_LEN, false)
        );
        $ntopFailBb = $fn->appendBasicBlock('ghbl_ntop_fail');
        $dupBb = $fn->appendBasicBlock('ghbl_dup');
        $ntopOk = $context->builder->icmp(Builder::INT_NE, $ntop, $i8p->constNull());
        $context->builder->branchIf($ntopOk, $dupBb, $ntopFailBb);

        $context->builder->positionAtEnd($ntopFailBb);
        $context->builder->branch($nextRpBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($dupBb);
        $count = $context->builder->load($countSlot);
        $dupLoopBb = $fn->appendBasicBlock('ghbl_dup_loop');
        $dupDoneBb = $fn->appendBasicBlock('ghbl_dup_done');
        $storeBb = $fn->appendBasicBlock('ghbl_store');
        $context->builder->branch($dupLoopBb);

        $context->builder->positionAtEnd($dupLoopBb);
        $iSlot = $context->builder->alloca($sizeT, 1, 'ghbl_i');
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $dupHeadBb = $fn->appendBasicBlock('ghbl_dup_head');
        $context->builder->branch($dupHeadBb);

        $context->builder->positionAtEnd($dupHeadBb);
        $i = $context->builder->load($iSlot);
        $iDone = $context->builder->icmp(Builder::INT_EQ, $i, $count);
        $dupCmpBb = $fn->appendBasicBlock('ghbl_dup_cmp');
        $context->builder->branchIf($iDone, $dupDoneBb, $dupCmpBb);

        $context->builder->positionAtEnd($dupCmpBb);
        $existing = $context->builder->gep(
            $storedBase,
            $context->builder->mul($i, $sizeT->constInt(self::IPBUF_LEN, false))
        );
        $cmp = $context->builder->call($context->lookupFunction('strcmp'), $existing, $ipbuf);
        $isDupBb = $fn->appendBasicBlock('ghbl_is_dup');
        $dupIncBb = $fn->appendBasicBlock('ghbl_dup_inc');
        $isDup = $context->builder->icmp(Builder::INT_EQ, $cmp, $zeroI32);
        $context->builder->branchIf($isDup, $isDupBb, $dupIncBb);

        $context->builder->positionAtEnd($isDupBb);
        $context->builder->branch($nextRpBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($dupIncBb);
        $context->builder->store(
            $context->builder->add($i, $sizeT->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($dupHeadBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($dupDoneBb);
        $fullBb = $fn->appendBasicBlock('ghbl_full');
        $atMax = $context->builder->icmp(
            Builder::INT_EQ,
            $count,
            $sizeT->constInt(self::MAX_ADDRS, false)
        );
        $context->builder->branchIf($atMax, $fullBb, $storeBb);

        $context->builder->positionAtEnd($fullBb);
        $context->builder->branch($loopDoneBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($storeBb);
        $dest = $context->builder->gep(
            $storedBase,
            $context->builder->mul($count, $sizeT->constInt(self::IPBUF_LEN, false))
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($dest, $voidPtr),
            $context->builder->pointerCast($ipbuf, $voidPtr),
            $sizeT->constInt(self::IPBUF_LEN, false)
        );
        $context->builder->store(
            $context->builder->add($count, $sizeT->constInt(1, false)),
            $countSlot
        );
        $context->builder->branch($nextRpBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($nextRpBb);
        $rpNext = $context->builder->load($rpSlot);
        $rpBytesNext = $context->builder->pointerCast($rpNext, $i8p);
        $nextFieldPtr = $context->builder->gep($rpBytesNext, $sizeT->constInt(self::ADDRINFO_OFF_NEXT, false));
        $context->builder->store(
            $context->builder->load(
                $context->builder->pointerCast($nextFieldPtr, $voidPtr->pointerType(0))
            ),
            $rpSlot
        );
        $context->builder->branch($loopHeadBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($loopDoneBb);
        $resHead = $context->builder->load($resSlot);
        $context->builder->call($context->lookupFunction('freeaddrinfo'), $resHead);
        $countFinal = $context->builder->load($countSlot);
        $emptyBb = $fn->appendBasicBlock('ghbl_empty');
        $buildBb = $fn->appendBasicBlock('ghbl_build');
        $hasAny = $context->builder->icmp(Builder::INT_SGT, $countFinal, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasAny, $buildBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($buildBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $buildLoopInitBb = $fn->appendBasicBlock('ghbl_build_init');
        $context->builder->branch($buildLoopInitBb);

        $context->builder->positionAtEnd($buildLoopInitBb);
        $biSlot = $context->builder->alloca($sizeT, 1, 'ghbl_bi');
        $context->builder->store($sizeT->constInt(0, false), $biSlot);
        $buildHeadBb = $fn->appendBasicBlock('ghbl_build_head');
        $context->builder->branch($buildHeadBb);

        $context->builder->positionAtEnd($buildHeadBb);
        $bi = $context->builder->load($biSlot);
        $buildDone = $context->builder->icmp(Builder::INT_EQ, $bi, $countFinal);
        $buildDoneBb = $fn->appendBasicBlock('ghbl_build_done');
        $buildBodyBb = $fn->appendBasicBlock('ghbl_build_body');
        $context->builder->branchIf($buildDone, $buildDoneBb, $buildBodyBb);

        $context->builder->positionAtEnd($buildBodyBb);
        $ipSrc = $context->builder->gep(
            $storedBase,
            $context->builder->mul($bi, $sizeT->constInt(self::IPBUF_LEN, false))
        );
        $ipLen = $context->builder->call($context->lookupFunction('strlen'), $ipSrc);
        $ipStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($ipLen, $i64),
            $ipSrc
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $bi,
            $ipStr
        );
        $context->builder->store(
            $context->builder->add($bi, $sizeT->constInt(1, false)),
            $biSlot
        );
        $context->builder->branch($buildHeadBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($buildDoneBb);
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureLibcDns(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $addrinfoResPtr = $voidPtr->pointerType(0);

        self::ensureExternal(
            $context,
            'getaddrinfo',
            $context->context->functionType($i32, false, $i8p, $i8p, $voidPtr, $addrinfoResPtr)
        );
        self::ensureExternal(
            $context,
            'freeaddrinfo',
            $context->context->functionType($voidTy, false, $voidPtr)
        );
        self::ensureExternal(
            $context,
            'inet_ntop',
            $context->context->functionType($i8p, false, $i32, $voidPtr, $i8p, $sizeT)
        );
        self::ensureExternal(
            $context,
            'memset',
            $context->context->functionType($voidPtr, false, $voidPtr, $i32, $sizeT)
        );
        self::ensureExternal(
            $context,
            'memcpy',
            $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT)
        );
        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
        );
        self::ensureExternal(
            $context,
            'strcmp',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_gethostbynamel');
        if (null === $fn) {
            throw new \LogicException('__compiler_gethostbynamel missing after GethostbynamelRuntime LLVM implement');
        }
        $context->registerFunction('__compiler_gethostbynamel', $fn);
    }
}
