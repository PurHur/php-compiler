<?php

declare(strict_types=1);

/**
 * LLVM JIT helpers for file_exists(), is_file(), and is_dir() via libc stat(2).
 *
 * Layout matches glibc struct stat on Linux x86_64 (Ubuntu 22.04 CI image).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStat
{
    /** sizeof(struct stat) on Linux x86_64 glibc */
    private const STAT_BUF_SIZE = 144;

    /** offsetof(struct stat, st_mode) on Linux x86_64 glibc */
    private const STAT_MODE_OFFSET = 24;

    /** offsetof(struct stat, st_size) on Linux x86_64 glibc */
    private const STAT_SIZE_OFFSET = 48;

    private const S_IFMT = 0xF000;
    private const S_IFREG = 0x8000;
    private const S_IFDIR = 0x4000;

    /** R_OK for access(2) — read permission (POSIX) */
    private const ACCESS_R_OK = 4;

    /** W_OK for access(2) — write permission (POSIX) */
    private const ACCESS_W_OK = 2;

    private static int $blockSerial = 0;

    public static function pathExists(Context $context, Value $str): Value
    {
        return self::statSucceeded($context, $str);
    }

    public static function pathIsFile(Context $context, Value $str): Value
    {
        return self::modeMatches($context, $str, self::S_IFREG);
    }

    public static function pathIsDir(Context $context, Value $str): Value
    {
        return self::modeMatches($context, $str, self::S_IFDIR);
    }

    public static function pathIsReadable(Context $context, Value $str): Value
    {
        return self::pathAccessOk($context, $str, self::ACCESS_R_OK);
    }

    public static function pathIsWritable(Context $context, Value $str): Value
    {
        return self::pathAccessOk($context, $str, self::ACCESS_W_OK);
    }

    private static function pathAccessOk(Context $context, Value $str, int $mode): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('access'),
            $pathPtr,
            $i32->constInt($mode, false)
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }

    /** @return Value __value__* (native long size, or boolean false when stat fails) */
    public static function pathFileSizeBoxed(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'filesize_stat_buf');
        $i8p = $context->getTypeFromString('int8*');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $ret = $context->builder->call(
            $context->lookupFunction('stat'),
            $pathPtr,
            $bufPtr
        );
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i32->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_NE, $ret, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'filesize_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'filesize_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'filesize_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $bytePtr = $context->builder->gep($bufPtr, $i64->constInt(self::STAT_SIZE_OFFSET, false));
        $sizePtr = $context->builder->pointerCast($bytePtr, $i64->pointerType(0));
        $size64 = $context->builder->load($sizePtr);
        JitValueBox::writeLong($context, $slot, $size64);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function statSucceeded(Context $context, Value $str): Value
    {
        $mode = self::loadModeOrFail($context, $str);
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_SLT, $mode, $i32->constInt(0, true));

        return $context->builder->not($failed);
    }

    private static function modeMatches(Context $context, Value $str, int $fileType): Value
    {
        $mode = self::loadModeOrFail($context, $str);
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_SLT, $mode, $i32->constInt(0, true));

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'stat_mode_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'stat_mode_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'stat_mode_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $falseVal = $context->constantFromBool(false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $masked = $context->builder->and(
            $mode,
            $i32->constInt(self::S_IFMT, false)
        );
        $matches = $context->builder->icmp(
            Builder::INT_EQ,
            $masked,
            $i32->constInt($fileType, false)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($falseVal->typeOf());
        $phi->addIncoming($falseVal, $failBlock);
        $phi->addIncoming($matches, $okBlock);

        return $phi;
    }

    /** @return Value i32 mode on success, or i32 -1 on failure */
    private static function loadModeOrFail(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'stat_buf');
        $bufPtr = $context->builder->gep($buf, $context->getTypeFromString('int64')->constInt(0, false));
        $ret = $context->builder->call(
            $context->lookupFunction('stat'),
            $pathPtr,
            $bufPtr
        );
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_NE, $ret, $zero);
        $modePtr = $context->builder->gep($buf, $i32->constInt(self::STAT_MODE_OFFSET, false));
        $mode = $context->builder->load($modePtr);
        $minusOne = $i32->constInt(-1, true);

        return $context->builder->select($failed, $minusOne, $mode);
    }
}
