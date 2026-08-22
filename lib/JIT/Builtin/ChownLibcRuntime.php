<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc chown/chgrp body for __compiler_chown/__compiler_chgrp (#32466).
 *
 * NestedJIT {@see ChownJitHelper} cannot chown under thin AOT: host \\chown() re-enters
 * __compiler_chown, and FFI is unavailable in the native binary. Platform chown(2) /
 * fchownat(2) is the justified thin ABI (php-src ext/standard/filestat.c).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(chown), PHP_FUNCTION(chgrp)
 */
final class ChownLibcRuntime
{
    private const PHPC_TYPE_NATIVE_LONG = 1;

    private const PHPC_TYPE_STRING = 4;

    private const AT_FDCWD = -100;

    private const AT_SYMLINK_NOFOLLOW = 0x100;

    /** Linux glibc x86_64: struct passwd/group uid/gid offset. */
    private const PW_UID_OFFSET = 16;

    private const GR_GID_OFFSET = 16;

    public static function emitChown(Context $context, LlvmFunction $fn): void
    {
        self::emitChx($context, $fn, false);
    }

    public static function emitChgrp(Context $context, LlvmFunction $fn): void
    {
        self::emitChx($context, $fn, true);
    }

    private static function emitChx(Context $context, LlvmFunction $fn, bool $group): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('chx_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $path = $fn->getParam(0);
        $idValue = $fn->getParam(1);
        $lchFlag = $fn->getParam(2);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);

        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $idValue, $valuePtr->constNull())
        );
        $fail = $fn->appendBasicBlock('chx_libc_fail');
        $body = $fn->appendBasicBlock('chx_libc_body');
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $p = self::stringData($context, $path);
        $id = self::resolveIdFromValue($context, $fn, $idValue, $group);
        $idBad = $context->builder->icmp(Builder::INT_EQ, $id, $i64->constInt(-1, true));
        $syscall = $fn->appendBasicBlock('chx_libc_syscall');
        $context->builder->branchIf($idBad, $fail, $syscall);

        $context->builder->positionAtEnd($syscall);
        $isLch = $context->builder->icmp(Builder::INT_NE, $lchFlag, $zero);
        $doAt = $fn->appendBasicBlock('chx_libc_do_at');
        $doPlain = $fn->appendBasicBlock('chx_libc_do_plain');
        $context->builder->branchIf($isLch, $doAt, $doPlain);

        $context->builder->positionAtEnd($doAt);
        $rcAt = $context->builder->call(
            $context->lookupFunction('fchownat'),
            $i32->constInt(self::AT_FDCWD, true),
            $p,
            $group ? $i32->constInt(-1, true) : $context->builder->truncOrBitCast($id, $i32),
            $group ? $context->builder->truncOrBitCast($id, $i32) : $i32->constInt(-1, true),
            $i32->constInt(self::AT_SYMLINK_NOFOLLOW, false)
        );
        $atOk = $context->builder->icmp(Builder::INT_EQ, $rcAt, $zero);
        $context->builder->returnValue($context->builder->select($atOk, $one, $zero));

        $context->builder->positionAtEnd($doPlain);
        $rc = $context->builder->call(
            $context->lookupFunction('chown'),
            $p,
            $group ? $i32->constInt(-1, true) : $context->builder->truncOrBitCast($id, $i32),
            $group ? $context->builder->truncOrBitCast($id, $i32) : $i32->constInt(-1, true)
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $one, $zero));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero);
    }

    private static function ensureLibc(Context $context): void
    {
        // Canonical decls (#33774): getNamedFunction first — bare lookup→addFunction
        // catch minted chown.1 / strtol.1 (#31894 / #32122 / #33550).
        LibcExtern::ensureChownFamily($context);
        LibcExtern::ensureStrtolDecl($context);

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        foreach ([
            ['__value__readLong', $i64, [$valuePtr]],
            ['__value__readString', $strPtr, [$valuePtr]],
            ['getpwnam', $i8p, [$i8p]],
            ['getgrnam', $i8p, [$i8p]],
        ] as [$name, $ret, $params]) {
            LibcExtern::ensureExternalDecl(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function resolveIdFromValue(Context $context, LlvmFunction $fn, Value $value, bool $group): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($value, $map['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isLong = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(self::PHPC_TYPE_NATIVE_LONG, false));
        $isStr = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(self::PHPC_TYPE_STRING, false));

        $prefix = $group ? 'gid' : 'uid';
        $idSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $context->builder->store($i64->constInt(-1, true), $idSlot);
        $longBlock = $fn->appendBasicBlock($prefix.'_long');
        $strBlock = $fn->appendBasicBlock($prefix.'_str');
        $done = $fn->appendBasicBlock($prefix.'_done');
        $next = $fn->appendBasicBlock($prefix.'_next');
        $context->builder->branchIf($isLong, $longBlock, $next);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->store($context->builder->call($context->lookupFunction('__value__readLong'), $value), $idSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($next);
        $context->builder->branchIf($isStr, $strBlock, $done);

        $context->builder->positionAtEnd($strBlock);
        $strObj = $context->builder->call($context->lookupFunction('__value__readString'), $value);
        $c = self::stringData($context, $strObj);
        $endSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i8p);
        $parsed = $context->builder->call($context->lookupFunction('strtol'), $c, $endSlot, $i32->constInt(10, false));
        $end = $context->builder->load($endSlot);
        $endZero = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($end), $i8->constInt(0, false));
        $endMoved = $context->builder->icmp(Builder::INT_NE, $end, $c);
        $numeric = $context->builder->and($endZero, $endMoved);
        $fromLookup = $fn->appendBasicBlock($prefix.'_lookup');
        $parsedBlock = $fn->appendBasicBlock($prefix.'_parsed');
        $context->builder->branchIf($numeric, $parsedBlock, $fromLookup);
        $context->builder->positionAtEnd($parsedBlock);
        $context->builder->store($parsed, $idSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($fromLookup);
        $entry = $context->builder->call($context->lookupFunction($group ? 'getgrnam' : 'getpwnam'), $c);
        $found = $context->builder->icmp(Builder::INT_NE, $entry, $i8p->constNull());
        $lookupDone = $fn->appendBasicBlock($prefix.'_lookup_done');
        $lookupSet = $fn->appendBasicBlock($prefix.'_lookup_set');
        $context->builder->branchIf($found, $lookupSet, $lookupDone);
        $context->builder->positionAtEnd($lookupSet);
        $off = $i64->constInt($group ? self::GR_GID_OFFSET : self::PW_UID_OFFSET, false);
        $ptr = $context->builder->gep($entry, $off);
        $id32 = $context->builder->load($context->builder->pointerCast($ptr, $i32->pointerType(0)));
        $context->builder->store($context->builder->zExt($id32, $i64), $idSlot);
        $context->builder->branch($lookupDone);
        $context->builder->positionAtEnd($lookupDone);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($idSlot);
    }
}
