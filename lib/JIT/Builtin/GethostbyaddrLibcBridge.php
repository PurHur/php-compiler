<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Standalone AOT libc bridge for __compiler_gethostbyaddr (#9474).
 *
 * Embedded JIT uses {@see GethostbyaddrRuntime} PHP helper; standalone links libc DNS directly.
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbyaddr)
 */
final class GethostbyaddrLibcBridge
{
    private const AF_INET = 2;

    private const IPBUF_LEN = 64;

    /** struct in_addr size on Linux x86_64. */
    private const IN_ADDR_SIZE = 4;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_gethostbyaddr');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_gethostbyaddr', $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_gethostbyaddr', $ft);
        self::implementGethostbyaddr($context, $fn);
        $context->registerFunction('__compiler_gethostbyaddr', $fn);
    }

    private static function implementGethostbyaddr(Context $context, Value $fn): void
    {
        self::ensureLibcDns($context);

        $entry = $fn->appendBasicBlock('ghba_libc_entry');
        $context->builder->positionAtEnd($entry);

        $ipArg = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroI64 = $i64->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $nullIp = $context->builder->icmp(Builder::INT_EQ, $ipArg, $strPtr->constNull());
        $invalidBb = $fn->appendBasicBlock('ghba_libc_invalid');
        $copyBb = $fn->appendBasicBlock('ghba_libc_copy');
        $context->builder->branchIf($nullIp, $invalidBb, $copyBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($copyBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($ipArg, $map['length']));
        $lenOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $len, $zeroI64),
            $context->builder->icmp(Builder::INT_SLT, $len, $i64->constInt(self::IPBUF_LEN, false))
        );
        $lenFailBb = $fn->appendBasicBlock('ghba_libc_len_fail');
        $lenOkBb = $fn->appendBasicBlock('ghba_libc_len_ok');
        $context->builder->branchIf($lenOk, $lenOkBb, $lenFailBb);

        $context->builder->positionAtEnd($lenFailBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($lenOkBb);
        $ipbuf = $context->builder->alloca($i8, self::IPBUF_LEN, 'ghba_ip');
        $valPtr = $context->builder->structGep($ipArg, $map['value']);
        $src = $context->builder->pointerCast($valPtr, $i8p);
        $len32 = $context->builder->trunc($len, $i32);
        $ipbufVoid = $context->bytePtr($ipbuf);
        $srcVoid = $context->bytePtr($src);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $ipbufVoid,
            $srcVoid,
            $context->builder->zExt($len32, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($ipbuf, $len32));

        $inAddr = $context->builder->alloca($i8, self::IN_ADDR_SIZE, 'ghba_inaddr');
        $inAddrPtr = $context->builder->pointerCast($inAddr, $i8p);
        $ptonRc = $context->builder->call(
            $context->lookupFunction('inet_pton'),
            $i32->constInt(self::AF_INET, false),
            $ipbuf,
            $inAddrPtr
        );
        $ptonOk = $context->builder->icmp(Builder::INT_EQ, $ptonRc, $oneI32);
        $ptonFailBb = $fn->appendBasicBlock('ghba_libc_pton_fail');
        $ptonOkBb = $fn->appendBasicBlock('ghba_libc_pton_ok');
        $context->builder->branchIf($ptonOk, $ptonOkBb, $ptonFailBb);

        $context->builder->positionAtEnd($ptonFailBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($ptonOkBb);
        $he = $context->builder->call(
            $context->lookupFunction('gethostbyaddr'),
            $inAddrPtr,
            $i32->constInt(self::IN_ADDR_SIZE, false),
            $i32->constInt(self::AF_INET, false)
        );
        $heIsNull = $context->builder->icmp(Builder::INT_EQ, $he, $i8p->constNull());
        $lookupFailBb = $fn->appendBasicBlock('ghba_libc_lookup_fail');
        $lookupOkBb = $fn->appendBasicBlock('ghba_libc_lookup_ok');
        $context->builder->branchIf($heIsNull, $lookupFailBb, $lookupOkBb);

        $context->builder->positionAtEnd($lookupFailBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($lookupOkBb);
        $i8pp = $context->getTypeFromString('int8**');
        $hNamePtr = $context->builder->load($context->builder->pointerCast($he, $i8pp));
        $hNameIsNull = $context->builder->icmp(Builder::INT_EQ, $hNamePtr, $i8p->constNull());
        $nameFailBb = $fn->appendBasicBlock('ghba_libc_name_fail');
        $nameOkBb = $fn->appendBasicBlock('ghba_libc_name_ok');
        $context->builder->branchIf($hNameIsNull, $nameFailBb, $nameOkBb);

        $context->builder->positionAtEnd($nameFailBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($nameOkBb);
        $nameLen = $context->builder->call($context->lookupFunction('strlen'), $hNamePtr);
        $nameLenI64 = $context->builder->zExt($nameLen, $i64);
        $nameLenZero = $context->builder->icmp(Builder::INT_EQ, $nameLenI64, $zeroI64);
        $emptyFailBb = $fn->appendBasicBlock('ghba_libc_empty_fail');
        $emptyOkBb = $fn->appendBasicBlock('ghba_libc_empty_ok');
        $context->builder->branchIf($nameLenZero, $emptyFailBb, $emptyOkBb);

        $context->builder->positionAtEnd($emptyFailBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($emptyOkBb);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $nameLenI64,
            $hNamePtr
        );
        $context->builder->returnValue($resultStr);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureLibcDns(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal(
            $context,
            'inet_pton',
            $context->context->functionType($i32, false, $i32, $i8p, $i8p)
        );
        self::ensureExternal(
            $context,
            'gethostbyaddr',
            $context->context->functionType($i8p, false, $i8p, $i32, $i32)
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
            '__string__init',
            $context->context->functionType(
                $context->getTypeFromString('__string__*'),
                false,
                $context->getTypeFromString('int64'),
                $context->getTypeFromString('char*')
            )
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
