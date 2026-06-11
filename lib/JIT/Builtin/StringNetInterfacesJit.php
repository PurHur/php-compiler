<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_net_get_interfaces (issue #6106).
 *
 * Mirrors ext/standard/VmNetInterfacesNative.php / php-src ext/standard/net.c.
 */
final class StringNetInterfacesJit
{
    private const AF_INET = 2;

    private const AF_INET6 = 10;

    private const IFF_UP = 1;

    private const IFF_BROADCAST = 2;

    private const IFF_POINTOPOINT = 16;

    private const INET_ADDRSTRLEN = 16;

    private const INET6_ADDRSTRLEN = 46;

    private const IFA_NEXT = 0;

    private const IFA_NAME = 8;

    private const IFA_FLAGS = 16;

    private const IFA_ADDR = 24;

    private const IFA_NETMASK = 32;

    private const IFA_BROADADDR = 40;

    private const IFA_DSTADDR = 48;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_net_get_interfaces');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        InetRuntime::ensureLinked($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_net_get_interfaces', $ft);
        self::implementNetGetInterfaces($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementNetGetInterfaces(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ngi_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $voidPtr = $context->getTypeFromString('void*');
        $i32 = $context->getTypeFromString('int32');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zeroI32 = $i32->constInt(0, false);

        $nullOutBb = $fn->appendBasicBlock('ngi_null_out');
        $bodyBb = $fn->appendBasicBlock('ngi_body');
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtrTy->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $addrsSlot = $context->builder->alloca($voidPtr, 1, 'ngi_addrs');
        $context->builder->store($voidPtr->constNull(), $addrsSlot);
        $status = $context->builder->call(
            $context->lookupFunction('getifaddrs'),
            $context->builder->pointerCast($addrsSlot, $voidPtr->pointerType(0))
        );
        $failBb = $fn->appendBasicBlock('ngi_fail');
        $okBb = $fn->appendBasicBlock('ngi_ok');
        $ok = $context->builder->icmp(Builder::INT_EQ, $status, $zeroI32);
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $zeroI32);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBb);
        $root = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call($context->lookupFunction('freeifaddrs'), $context->builder->load($addrsSlot));
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $root);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function processIfaddr(Context $context, LlvmFunction $fn, Value $root, Value $current, BasicBlock $advanceBb, Value $ntopBuf): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $nameCstr = self::loadPtrAt($context, $current, self::IFA_NAME);
        $skipBb = $fn->appendBasicBlock('ngi_skip');
        $nameBb = $fn->appendBasicBlock('ngi_name');
        $nameNull = $context->builder->icmp(Builder::INT_EQ, $nameCstr, $i8p->constNull());
        $context->builder->branchIf($nameNull, $skipBb, $nameBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($advanceBb);

        $context->builder->positionAtEnd($nameBb);
        $nameLen = $context->builder->call($context->lookupFunction('strlen'), $nameCstr);
        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($nameLen, $i64),
            $nameCstr
        );

        $flags = self::loadI32At($context, $current, self::IFA_FLAGS);
        $addrPtr = self::loadPtrAt($context, $current, self::IFA_ADDR);

        $hasIface = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $root,
            $nameStr
        );
        $ifaceExistsBb = $fn->appendBasicBlock('ngi_iface_exists');
        $ifaceNewBb = $fn->appendBasicBlock('ngi_iface_new');
        $mergeBb = $fn->appendBasicBlock('ngi_merge');
        $context->builder->branchIf($hasIface, $ifaceExistsBb, $ifaceNewBb);

        $context->builder->positionAtEnd($ifaceNewBb);
        $ifaceHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $up = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i32->constInt(self::IFF_UP, false)),
            $i32->constInt(0, false)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ifaceHt,
            self::literalString($context, 'up'),
            $up
        );
        $unicastHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ifaceHt,
            self::literalString($context, 'unicast'),
            $unicastHt
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $root,
            $nameStr,
            $ifaceHt
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($ifaceExistsBb);
        $ifaceHt = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyHashtable'),
            $root,
            $nameStr
        );
        $unicastHt = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyHashtable'),
            $ifaceHt,
            self::literalString($context, 'unicast')
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $entryHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $entryHt,
            self::literalString($context, 'flags'),
            $context->builder->sext($flags, $i64)
        );
        self::maybeSetSockaddr($context, $entryHt, $addrPtr, 'family', true, $ntopBuf);
        self::maybeSetSockaddr($context, $entryHt, $addrPtr, 'address', false, $ntopBuf);

        $idx = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $unicastHt);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $unicastHt,
            $idx,
            $entryHt
        );
        $context->builder->branch($advanceBb);
    }

    private static function maybeSetSockaddr(
        Context $context,
        Value $entryHt,
        Value $sockPtr,
        string $key,
        bool $familyOnly,
        Value $ntopBuf
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $i16 = $context->getTypeFromString('int16');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');

        $family = $context->builder->zExt(
            $context->builder->load($context->builder->pointerCast($sockPtr, $i16->pointerType(0))),
            $i32
        );
        if ($familyOnly) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyLong'),
                $entryHt,
                self::literalString($context, $key),
                $context->builder->sext($family, $i64)
            );

            return;
        }

        $isV4 = $context->builder->icmp(Builder::INT_EQ, $family, $i32->constInt(self::AF_INET, false));
        $isV6 = $context->builder->icmp(Builder::INT_EQ, $family, $i32->constInt(self::AF_INET6, false));

        $bufLen = $context->builder->select(
            $isV4,
            $sizeT->constInt(self::INET_ADDRSTRLEN, false),
            $sizeT->constInt(self::INET6_ADDRSTRLEN, false)
        );
        $src = $context->builder->select(
            $isV4,
            $context->builder->pointerCast($context->builder->gep($sockPtr, $i8->constInt(4, false)), $context->getTypeFromString('void*')),
            $context->builder->pointerCast($context->builder->gep($sockPtr, $i8->constInt(8, false)), $context->getTypeFromString('void*'))
        );
        $af = $context->builder->select(
            $isV4,
            $i32->constInt(self::AF_INET, false),
            $i32->constInt(self::AF_INET6, false)
        );
        $context->builder->call(
            $context->lookupFunction('inet_ntop'),
            $af,
            $src,
            $context->builder->pointerCast($ntopBuf, $charPtr),
            $bufLen
        );
        $hostCstr = $context->builder->pointerCast($ntopBuf, $i8p);
        $hostLen = $context->builder->call($context->lookupFunction('strlen'), $hostCstr);
        $hostStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($hostLen, $i64),
            $hostCstr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $entryHt,
            self::literalString($context, $key),
            $hostStr
        );
    }

    private static function loadPtrAt(Context $context, Value $base, int $offset): Value
    {
        $voidPtr = $context->getTypeFromString('void*');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));

        return $context->builder->load($context->builder->pointerCast($ptr, $voidPtr->pointerType(0)));
    }

    private static function loadI32At(Context $context, Value $base, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));

        return $context->builder->load($context->builder->pointerCast($ptr, $i32->pointerType(0)));
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $voidPtr = $context->getTypeFromString('void*');
        $voidPtrPtr = $voidPtr->pointerType(0);
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['getifaddrs', $i32, [$voidPtrPtr]],
            ['freeifaddrs', $voidTy, [$voidPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__hashtable__setStringKeyBool', $voidTy, [$htPtr, $strPtr, $i1]],
            ['__hashtable__setStringKeyHashtable', $voidTy, [$htPtr, $strPtr, $htPtr]],
            ['__hashtable__setHashtableAt', $voidTy, [$htPtr, $sizeT, $htPtr]],
            ['__hashtable__readStringKeyHashtable', $htPtr, [$htPtr, $strPtr]],
            ['__hashtable__offsetIsSetStringKey', $i1, [$htPtr, $strPtr]],
            ['__hashtable__getNumElements', $sizeT, [$htPtr]],
            ['__string__init', $strPtr, [$i64, $charPtr]],
            ['__value__writeBool', $voidTy, [$valuePtr, $i32]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
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

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_net_get_interfaces');
        if (null === $fn) {
            throw new \LogicException('__compiler_net_get_interfaces missing after StringNetInterfacesJit LLVM implement');
        }
        $context->registerFunction('__compiler_net_get_interfaces', $fn);
    }
}
