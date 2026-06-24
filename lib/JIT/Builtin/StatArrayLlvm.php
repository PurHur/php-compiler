<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM for __phpc_stat when nested StatArrayJitHelper cannot compile (#9585).
 *
 * Normal JIT uses {@see StatArrayRuntime} PHP bridge; standalone keeps glibc stat layout here only.
 */
final class StatArrayLlvm
{
    private const STAT_BUF_SIZE = 144;
    private const STAT_DEV_OFFSET = 0;
    private const STAT_INO_OFFSET = 8;
    private const STAT_NLINK_OFFSET = 16;
    private const STAT_MODE_OFFSET = 24;
    private const STAT_UID_OFFSET = 28;
    private const STAT_GID_OFFSET = 32;
    private const STAT_RDEV_OFFSET = 40;
    private const STAT_SIZE_OFFSET = 48;
    private const STAT_BLKSIZE_OFFSET = 56;
    private const STAT_BLOCKS_OFFSET = 64;
    private const STAT_ATIME_OFFSET = 72;
    private const STAT_MTIME_OFFSET = 88;
    private const STAT_CTIME_OFFSET = 104;

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_stat');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_stat', $probe);

            return;
        }

        self::ensureLibc($context);
        $fn = self::declareFunction($context);
        self::emitStat($context, $fn);
        $context->registerFunction('__phpc_stat', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context): LlvmFunction
    {
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->module->addFunction(
            '__phpc_stat',
            $context->context->functionType($htPtr, false, $strPtr, $i32)
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setLongAt', $voidTy, [$htPtr, $sizeT, $i64]],
            ['__string__init', $strPtr, [$i64, $i8p]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction($name, $context->context->functionType($ret, false, ...$params));
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function stackBytesPtr(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast($slot, $context->getTypeFromString('int8*'));
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $litGlobal = $context->constantStringFromString($text);
        $litPtr = $context->builder->load($litGlobal);
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($litPtr, $map['value']);
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
    }

    private static function statFieldI64(Context $context, Value $statBase, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $at = $context->builder->gep($statBase, $i64->constInt($offset, false));

        return $context->builder->load($context->builder->pointerCast($at, $i64->pointerType(0)));
    }

    private static function statFieldI32ToI64(Context $context, Value $statBase, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $at = $context->builder->gep($statBase, $i64->constInt($offset, false));
        $v = $context->builder->load($context->builder->pointerCast($at, $i32->pointerType(0)));

        return $context->builder->zExt($v, $i64);
    }

    private static function statIndex(Context $context, Value $ht, int $index, Value $value): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $sizeT->constInt($index, false),
            $value
        );
    }

    private static function statKey(Context $context, Value $ht, string $key, Value $value): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            self::cstrToString($context, self::literalCstr($context, $key)),
            $value
        );
    }

    private static function emitStat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $path = $fn->getParam(0);
        $useLstat = $fn->getParam(1);
        $nullHt = $htPtr->constNull();
        $zero = $i32->constInt(0, false);

        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $fail = $fn->appendBasicBlock('stat_fail');
        $run = $fn->appendBasicBlock('stat_run');
        $context->builder->branchIf($nullPath, $fail, $run);

        $context->builder->positionAtEnd($run);
        $p = self::stringData($context, $path);
        $stSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::STAT_BUF_SIZE));
        $stBase = self::stackBytesPtr($context, $stSlot);
        $isLstat = $context->builder->icmp(Builder::INT_NE, $useLstat, $zero);
        $doLstat = $fn->appendBasicBlock('stat_do_lstat');
        $doStat = $fn->appendBasicBlock('stat_do_stat');
        $afterCall = $fn->appendBasicBlock('stat_after');
        $context->builder->branchIf($isLstat, $doLstat, $doStat);

        $context->builder->positionAtEnd($doStat);
        $rcStat = $context->builder->call($context->lookupFunction('stat'), $p, $stBase);
        $context->builder->branch($afterCall);
        $doStatTail = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($doLstat);
        $rcLstat = $context->builder->call($context->lookupFunction('lstat'), $p, $stBase);
        $context->builder->branch($afterCall);
        $doLstatTail = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($afterCall);
        $rcPhi = $context->builder->phi($i32);
        $rcPhi->addIncoming($rcStat, $doStatTail);
        $rcPhi->addIncoming($rcLstat, $doLstatTail);
        $bad = $context->builder->icmp(Builder::INT_NE, $rcPhi, $zero);
        $fill = $fn->appendBasicBlock('stat_fill');
        $context->builder->branchIf($bad, $fail, $fill);

        $context->builder->positionAtEnd($fill);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $dev = self::statFieldI64($context, $stBase, self::STAT_DEV_OFFSET);
        $ino = self::statFieldI64($context, $stBase, self::STAT_INO_OFFSET);
        $mode = self::statFieldI32ToI64($context, $stBase, self::STAT_MODE_OFFSET);
        $nlink = self::statFieldI64($context, $stBase, self::STAT_NLINK_OFFSET);
        $uid = self::statFieldI32ToI64($context, $stBase, self::STAT_UID_OFFSET);
        $gid = self::statFieldI32ToI64($context, $stBase, self::STAT_GID_OFFSET);
        $rdev = self::statFieldI64($context, $stBase, self::STAT_RDEV_OFFSET);
        $size = self::statFieldI64($context, $stBase, self::STAT_SIZE_OFFSET);
        $atime = self::statFieldI64($context, $stBase, self::STAT_ATIME_OFFSET);
        $mtime = self::statFieldI64($context, $stBase, self::STAT_MTIME_OFFSET);
        $ctime = self::statFieldI64($context, $stBase, self::STAT_CTIME_OFFSET);
        $blksize = self::statFieldI64($context, $stBase, self::STAT_BLKSIZE_OFFSET);
        $blocks = self::statFieldI64($context, $stBase, self::STAT_BLOCKS_OFFSET);
        self::statIndex($context, $ht, 0, $dev);
        self::statIndex($context, $ht, 1, $ino);
        self::statIndex($context, $ht, 2, $mode);
        self::statIndex($context, $ht, 3, $nlink);
        self::statIndex($context, $ht, 4, $uid);
        self::statIndex($context, $ht, 5, $gid);
        self::statIndex($context, $ht, 6, $rdev);
        self::statIndex($context, $ht, 7, $size);
        self::statIndex($context, $ht, 8, $atime);
        self::statIndex($context, $ht, 9, $mtime);
        self::statIndex($context, $ht, 10, $ctime);
        self::statIndex($context, $ht, 11, $blksize);
        self::statIndex($context, $ht, 12, $blocks);
        self::statKey($context, $ht, 'dev', $dev);
        self::statKey($context, $ht, 'ino', $ino);
        self::statKey($context, $ht, 'mode', $mode);
        self::statKey($context, $ht, 'nlink', $nlink);
        self::statKey($context, $ht, 'uid', $uid);
        self::statKey($context, $ht, 'gid', $gid);
        self::statKey($context, $ht, 'rdev', $rdev);
        self::statKey($context, $ht, 'size', $size);
        self::statKey($context, $ht, 'atime', $atime);
        self::statKey($context, $ht, 'mtime', $mtime);
        self::statKey($context, $ht, 'ctime', $ctime);
        self::statKey($context, $ht, 'blksize', $blksize);
        self::statKey($context, $ht, 'blocks', $blocks);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullHt);
    }
}
