<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM delegates for inet_* / ip2long / long2ip (issue #3225).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ip2long), long2ip, inet_ntop, inet_pton
 */
final class InetRuntime
{
    private const AF_INET = 2;

    private const AF_INET6 = 10;

    private const INET_ADDRSTRLEN = 16;

    private const INET6_ADDRSTRLEN = 46;

    private const IPBUF_LEN = 128;

    private const IN_ADDR_SIZE = 4;

    private const IN6_ADDR_SIZE = 16;

    private const UINT32_MAX = 4294967295;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLibc($context);
        self::implementIp2long($context);
        self::implementLong2ip($context);
        self::implementInetPton($context);
        self::implementInetNtop($context);
        self::registerLinkedRuntime($context);
    }

    private static function implementIp2long(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ip2long');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valuePtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_ip2long', $ft);

        $entry = $fn->appendBasicBlock('ip2long_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $ipArg = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);

        $nullIp = $context->builder->icmp(Builder::INT_EQ, $ipArg, $strPtr->constNull());
        $invalidBb = $fn->appendBasicBlock('ip2long_invalid');
        $copyBb = $fn->appendBasicBlock('ip2long_copy');
        $context->builder->branchIf($nullIp, $invalidBb, $copyBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($copyBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($ipArg, $map['length']));
        $lenOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $len, $zeroI64),
            $context->builder->icmp(Builder::INT_SLT, $len, $i64->constInt(self::IPBUF_LEN, false))
        );
        $lenFailBb = $fn->appendBasicBlock('ip2long_len_fail');
        $lenOkBb = $fn->appendBasicBlock('ip2long_len_ok');
        $context->builder->branchIf($lenOk, $lenOkBb, $lenFailBb);

        $context->builder->positionAtEnd($lenFailBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($lenOkBb);
        $ipbuf = $context->builder->alloca($i8, self::IPBUF_LEN, 'ip2long_buf');
        $valPtr = $context->builder->structGep($ipArg, $map['value']);
        $src = $context->builder->pointerCast($valPtr, $i8p);
        $len32 = $context->builder->trunc($len, $i32);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($ipbuf),
            $context->bytePtr($src),
            $context->builder->zExt($len32, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($ipbuf, $len32));

        self::ensureIp2longScanf($context);
        $octets = $context->builder->alloca($i32, 4, 'ip2long_octets');
        $consumed = $context->builder->alloca($i32, 1, 'ip2long_consumed');
        $scanFmt = $context->builder->globalStringPointer('%u.%u.%u.%u%n');
        $scanRc = $context->builder->call(
            $context->lookupFunction('sscanf'),
            $context->bytePtr($ipbuf),
            $context->bytePtr($scanFmt),
            $context->builder->gep($octets, $i32->constInt(0, false)),
            $context->builder->gep($octets, $i32->constInt(1, false)),
            $context->builder->gep($octets, $i32->constInt(2, false)),
            $context->builder->gep($octets, $i32->constInt(3, false)),
            $context->bytePtr($consumed)
        );
        $scanOk = $context->builder->icmp(Builder::INT_EQ, $scanRc, $i32->constInt(4, false));
        $scanFailBb = $fn->appendBasicBlock('ip2long_scan_fail');
        $scanOkBb = $fn->appendBasicBlock('ip2long_scan_ok');
        $context->builder->branchIf($scanOk, $scanOkBb, $scanFailBb);

        $context->builder->positionAtEnd($scanFailBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($scanOkBb);
        $consumedVal = $context->builder->load($consumed);
        $ipLen = $context->builder->call($context->lookupFunction('strlen'), $context->bytePtr($ipbuf));
        $consumedLen = $context->builder->sext($consumedVal, $sizeT);
        $lenMatch = $context->builder->icmp(Builder::INT_EQ, $consumedLen, $ipLen);
        $lenMismatchBb = $fn->appendBasicBlock('ip2long_consumed_fail');
        $roundtripBb = $fn->appendBasicBlock('ip2long_roundtrip');
        $context->builder->branchIf($lenMatch, $roundtripBb, $lenMismatchBb);

        $context->builder->positionAtEnd($lenMismatchBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($roundtripBb);
        $roundBuf = $context->builder->alloca($i8, self::IPBUF_LEN, 'ip2long_round');
        $roundFmt = $context->builder->globalStringPointer('%u.%u.%u.%u');
        $o0 = $context->builder->load($context->builder->gep($octets, $i32->constInt(0, false)));
        $o1 = $context->builder->load($context->builder->gep($octets, $i32->constInt(1, false)));
        $o2 = $context->builder->load($context->builder->gep($octets, $i32->constInt(2, false)));
        $o3 = $context->builder->load($context->builder->gep($octets, $i32->constInt(3, false)));
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $context->bytePtr($roundBuf),
            $sizeT->constInt(self::IPBUF_LEN, false),
            $context->bytePtr($roundFmt),
            $o0,
            $o1,
            $o2,
            $o3
        );
        $cmpRc = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $context->bytePtr($ipbuf),
            $context->bytePtr($roundBuf)
        );
        $roundOk = $context->builder->icmp(Builder::INT_EQ, $cmpRc, $zeroI32);
        $roundFailBb = $fn->appendBasicBlock('ip2long_round_fail');
        $rangeBb = $fn->appendBasicBlock('ip2long_range');
        $context->builder->branchIf($roundOk, $rangeBb, $roundFailBb);

        $context->builder->positionAtEnd($roundFailBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($rangeBb);
        $maxOctet = $i32->constInt(255, false);
        $inRange = $context->builder->and(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_ULE, $o0, $maxOctet),
                $context->builder->icmp(Builder::INT_ULE, $o1, $maxOctet)
            ),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_ULE, $o2, $maxOctet),
                $context->builder->icmp(Builder::INT_ULE, $o3, $maxOctet)
            )
        );
        $rangeFailBb = $fn->appendBasicBlock('ip2long_range_fail');
        $packBb = $fn->appendBasicBlock('ip2long_pack');
        $context->builder->branchIf($inRange, $packBb, $rangeFailBb);

        $context->builder->positionAtEnd($rangeFailBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($packBb);
        $shift24 = $context->builder->shl($context->builder->zExt($o0, $i64), $i64->constInt(24, false));
        $shift16 = $context->builder->shl($context->builder->zExt($o1, $i64), $i64->constInt(16, false));
        $shift8 = $context->builder->shl($context->builder->zExt($o2, $i64), $i64->constInt(8, false));
        $packed = $context->builder->or(
            $context->builder->or($shift24, $shift16),
            $context->builder->or($shift8, $context->builder->zExt($o3, $i64))
        );
        $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $packed);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function ensureIp2longScanf(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        self::ensureExternal(
            $context,
            'sscanf',
            $context->context->functionType($i32, true, $i8p, $i8p, $i32->pointerType(0), $i32->pointerType(0), $i32->pointerType(0), $i32->pointerType(0), $i32->pointerType(0))
        );
        self::ensureExternal(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p, $i32, $i32, $i32, $i32)
        );
        self::ensureExternal(
            $context,
            'strcmp',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
    }

    private static function implementLong2ip(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_long2ip');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valuePtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_long2ip', $ft);

        $entry = $fn->appendBasicBlock('long2ip_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $addr = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i1 = $context->getTypeFromString('int1');
        $zeroI64 = $i64->constInt(0, false);
        $maxU32 = $i64->constInt(self::UINT32_MAX, false);

        $neg = $context->builder->icmp(Builder::INT_SLT, $addr, $zeroI64);
        $tooBig = $context->builder->icmp(Builder::INT_UGT, $addr, $maxU32);
        $invalid = $context->builder->or($neg, $tooBig);
        $invalidBb = $fn->appendBasicBlock('long2ip_invalid');
        $okBb = $fn->appendBasicBlock('long2ip_ok');
        $context->builder->branchIf($invalid, $invalidBb, $okBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($okBb);
        $addrU32 = $context->builder->trunc($addr, $i32);
        $netAddr = $context->builder->call($context->lookupFunction('htonl'), $addrU32);
        $ntoaPtr = $context->builder->call(
            $context->lookupFunction('inet_ntoa'),
            $netAddr
        );
        $sizeT = $context->getTypeFromString('size_t');
        $ntoaLen = $context->builder->call($context->lookupFunction('strlen'), $ntoaPtr);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($ntoaLen, $i64),
            $ntoaPtr
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $resultStr);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementInetPton(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_inet_pton');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_inet_pton', $ft);

        $entry = $fn->appendBasicBlock('pton_entry');
        $context->builder->positionAtEnd($entry);

        $addrArg = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $nullArg = $context->builder->icmp(Builder::INT_EQ, $addrArg, $strPtr->constNull());
        $invalidBb = $fn->appendBasicBlock('pton_invalid');
        $copyBb = $fn->appendBasicBlock('pton_copy');
        $context->builder->branchIf($nullArg, $invalidBb, $copyBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($copyBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($addrArg, $map['length']));
        $lenOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $len, $zeroI64),
            $context->builder->icmp(Builder::INT_SLT, $len, $i64->constInt(self::IPBUF_LEN, false))
        );
        $lenFailBb = $fn->appendBasicBlock('pton_len_fail');
        $lenOkBb = $fn->appendBasicBlock('pton_len_ok');
        $context->builder->branchIf($lenOk, $lenOkBb, $lenFailBb);

        $context->builder->positionAtEnd($lenFailBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($lenOkBb);
        $ipbuf = $context->builder->alloca($i8, self::IPBUF_LEN, 'pton_buf');
        $valPtr = $context->builder->structGep($addrArg, $map['value']);
        $src = $context->builder->pointerCast($valPtr, $i8p);
        $len32 = $context->builder->trunc($len, $i32);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($ipbuf),
            $context->bytePtr($src),
            $context->builder->zExt($len32, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($ipbuf, $len32));

        $buf4 = $context->builder->alloca($i8, self::IN_ADDR_SIZE, 'pton_v4');
        $pton4 = $context->builder->call(
            $context->lookupFunction('inet_pton'),
            $i32->constInt(self::AF_INET, false),
            $context->bytePtr($ipbuf),
            $context->bytePtr($buf4)
        );
        $v4Ok = $context->builder->icmp(Builder::INT_EQ, $pton4, $oneI32);
        $try6Bb = $fn->appendBasicBlock('pton_try6');
        $v4OkBb = $fn->appendBasicBlock('pton_v4_ok');
        $context->builder->branchIf($v4Ok, $v4OkBb, $try6Bb);

        $context->builder->positionAtEnd($v4OkBb);
        $result4 = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(self::IN_ADDR_SIZE, false),
            $context->bytePtr($buf4)
        );
        $context->builder->returnValue($result4);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($try6Bb);
        $buf16 = $context->builder->alloca($i8, self::IN6_ADDR_SIZE, 'pton_v6');
        $pton6 = $context->builder->call(
            $context->lookupFunction('inet_pton'),
            $i32->constInt(self::AF_INET6, false),
            $context->bytePtr($ipbuf),
            $context->bytePtr($buf16)
        );
        $v6Ok = $context->builder->icmp(Builder::INT_EQ, $pton6, $oneI32);
        $failBb = $fn->appendBasicBlock('pton_fail');
        $v6OkBb = $fn->appendBasicBlock('pton_v6_ok');
        $context->builder->branchIf($v6Ok, $v6OkBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($v6OkBb);
        $result16 = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(self::IN6_ADDR_SIZE, false),
            $context->bytePtr($buf16)
        );
        $context->builder->returnValue($result16);
        $context->builder->clearInsertionPosition();
    }

    private static function implementInetNtop(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_inet_ntop');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_inet_ntop', $ft);

        $entry = $fn->appendBasicBlock('ntop_entry');
        $context->builder->positionAtEnd($entry);

        $inArg = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI64 = $i64->constInt(0, false);

        $nullArg = $context->builder->icmp(Builder::INT_EQ, $inArg, $strPtr->constNull());
        $invalidBb = $fn->appendBasicBlock('ntop_invalid');
        $checkBb = $fn->appendBasicBlock('ntop_check');
        $context->builder->branchIf($nullArg, $invalidBb, $checkBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($checkBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($inArg, $map['length']));
        $isV4 = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(self::IN_ADDR_SIZE, false));
        $isV6 = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(self::IN6_ADDR_SIZE, false));
        $v4Bb = $fn->appendBasicBlock('ntop_v4');
        $v6CheckBb = $fn->appendBasicBlock('ntop_v6_check');
        $context->builder->branchIf($isV4, $v4Bb, $v6CheckBb);

        $context->builder->positionAtEnd($v6CheckBb);
        $failBb = $fn->appendBasicBlock('ntop_fail');
        $v6Bb = $fn->appendBasicBlock('ntop_v6');
        $context->builder->branchIf($isV6, $v6Bb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($v4Bb);
        $src4 = $context->bytePtr($context->builder->structGep($inArg, $map['value']));
        $outbuf4 = $context->builder->alloca($i8, self::INET_ADDRSTRLEN, 'ntop_buf4');
        $ntop4 = $context->builder->call(
            $context->lookupFunction('inet_ntop'),
            $i32->constInt(self::AF_INET, false),
            $src4,
            $context->bytePtr($outbuf4),
            $sizeT->constInt(self::INET_ADDRSTRLEN, false)
        );
        $ntop4Null = $context->builder->icmp(Builder::INT_EQ, $ntop4, $i8p->constNull());
        $v4FailBb = $fn->appendBasicBlock('ntop_v4_fail');
        $v4OkBb = $fn->appendBasicBlock('ntop_v4_ok');
        $context->builder->branchIf($ntop4Null, $v4FailBb, $v4OkBb);

        $context->builder->positionAtEnd($v4FailBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($v4OkBb);
        $len4 = $context->builder->call($context->lookupFunction('strlen'), $ntop4);
        $result4 = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len4, $i64),
            $ntop4
        );
        $context->builder->returnValue($result4);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($v6Bb);
        $src6 = $context->bytePtr($context->builder->structGep($inArg, $map['value']));
        $outbuf6 = $context->builder->alloca($i8, self::INET6_ADDRSTRLEN, 'ntop_buf6');
        $ntop6 = $context->builder->call(
            $context->lookupFunction('inet_ntop'),
            $i32->constInt(self::AF_INET6, false),
            $src6,
            $context->bytePtr($outbuf6),
            $sizeT->constInt(self::INET6_ADDRSTRLEN, false)
        );
        $ntop6Null = $context->builder->icmp(Builder::INT_EQ, $ntop6, $i8p->constNull());
        $v6FailBb = $fn->appendBasicBlock('ntop_v6_fail');
        $v6OkBb = $fn->appendBasicBlock('ntop_v6_ok');
        $context->builder->branchIf($ntop6Null, $v6FailBb, $v6OkBb);

        $context->builder->positionAtEnd($v6FailBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($v6OkBb);
        $len6 = $context->builder->call($context->lookupFunction('strlen'), $ntop6);
        $result6 = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len6, $i64),
            $ntop6
        );
        $context->builder->returnValue($result6);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal(
            $context,
            'inet_aton',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
        self::ensureExternal(
            $context,
            'inet_ntoa',
            $context->context->functionType($i8p, false, $i32)
        );
        self::ensureExternal(
            $context,
            'inet_pton',
            $context->context->functionType($i32, false, $i32, $i8p, $i8p)
        );
        self::ensureExternal(
            $context,
            'inet_ntop',
            $context->context->functionType($i8p, false, $i32, $i8p, $i8p, $sizeT)
        );
        self::ensureExternal(
            $context,
            'ntohl',
            $context->context->functionType($i32, false, $i32)
        );
        self::ensureExternal(
            $context,
            'htonl',
            $context->context->functionType($i32, false, $i32)
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
        foreach (['__compiler_ip2long', '__compiler_long2ip', '__compiler_inet_pton', '__compiler_inet_ntop'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after InetRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
