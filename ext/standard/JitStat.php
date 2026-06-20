<?php

declare(strict_types=1);

/**
 * LLVM JIT helpers for file_exists(), is_file(), and is_dir() via libc stat(2).
 *
 * Layout matches glibc struct stat on Linux x86_64 (Ubuntu 22.04 CI image).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StatCacheRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStat
{
    /** sizeof(struct stat) on Linux x86_64 glibc */
    private const STAT_BUF_SIZE = 144;

    /** offsetof(struct stat, st_dev) on Linux x86_64 glibc */
    private const STAT_DEV_OFFSET = 0;

    /** offsetof(struct stat, st_ino) on Linux x86_64 glibc */
    private const STAT_INO_OFFSET = 8;

    /** offsetof(struct stat, st_mode) on Linux x86_64 glibc */
    private const STAT_MODE_OFFSET = 24;

    /** offsetof(struct stat, st_size) on Linux x86_64 glibc */
    private const STAT_SIZE_OFFSET = 48;

    /** offsetof(struct stat, st_atim) on Linux x86_64 glibc */
    private const STAT_ATIME_OFFSET = 72;

    /** offsetof(struct stat, st_mtim) on Linux x86_64 glibc */
    private const STAT_MTIME_OFFSET = 88;

    /** sizeof(struct statvfs) on Linux x86_64 glibc */
    private const STATVFS_BUF_SIZE = 112;

    /** offsetof(struct statvfs, f_frsize) on Linux x86_64 glibc */
    private const STATVFS_FRSIZE_OFFSET = 8;

    /** offsetof(struct statvfs, f_blocks) on Linux x86_64 glibc */
    private const STATVFS_BLOCKS_OFFSET = 16;

    /** offsetof(struct statvfs, f_bavail) on Linux x86_64 glibc */
    private const STATVFS_BAVAIL_OFFSET = 32;

    /** offsetof(struct stat, st_ctim) on Linux x86_64 glibc */
    private const STAT_CTIME_OFFSET = 104;

    /** offsetof(struct stat, st_uid) on Linux x86_64 glibc */
    private const STAT_UID_OFFSET = 28;

    /** offsetof(struct stat, st_gid) on Linux x86_64 glibc */
    private const STAT_GID_OFFSET = 32;

    private const S_IFMT = 0xF000;
    private const S_IFIFO = 0x1000;
    private const S_IFCHR = 0x2000;
    private const S_IFDIR = 0x4000;
    private const S_IFBLK = 0x6000;
    private const S_IFREG = 0x8000;
    private const S_IFLNK = 0xA000;
    private const S_IFSOCK = 0xC000;

    /** R_OK for access(2) — read permission (POSIX) */
    private const ACCESS_R_OK = 4;

    /** W_OK for access(2) — write permission (POSIX) */
    private const ACCESS_W_OK = 2;

    /** X_OK for access(2) — execute permission (POSIX) */
    private const ACCESS_X_OK = 1;

    private const S_IRUSR = 0x0100;

    private const S_IWUSR = 0x0080;

    private const S_IXUSR = 0x0040;

    private const S_IRGRP = 0x0020;

    private const S_IWGRP = 0x0010;

    private const S_IXGRP = 0x0008;

    private const S_IROTH = 0x0004;

    private const S_IWOTH = 0x0002;

    private const S_IXOTH = 0x0001;

    /** Any execute bit in st_mode (owner/group/other). */
    private const S_IXANY = self::S_IXUSR | self::S_IXGRP | self::S_IXOTH;

    /** php-src S_IXROOT */
    private const S_IXROOT = self::S_IRUSR | self::S_IWUSR | self::S_IXUSR | self::S_IXGRP | self::S_IROTH | self::S_IXOTH;

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

    public static function pathIsLink(Context $context, Value $str): Value
    {
        return self::modeMatches($context, $str, self::S_IFLNK, 'lstat');
    }

    public static function pathIsReadable(Context $context, Value $str): Value
    {
        return self::pathAccessOk($context, $str, self::ACCESS_R_OK);
    }

    public static function pathIsWritable(Context $context, Value $str): Value
    {
        return self::pathAccessOk($context, $str, self::ACCESS_W_OK);
    }

    public static function pathIsExecutable(Context $context, Value $str): Value
    {
        return self::pathAccessOk($context, $str, self::ACCESS_X_OK);
    }

    /** @return Value */
    public static function pathFiletypeBoxed(Context $context, Value $str): Value
    {
        $mode = self::loadModeOrFail($context, $str, 'lstat');
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_SLT, $mode, $i32->constInt(0, true));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'filetype_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'filetype_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'filetype_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        self::writeFiletypeFromMode($context, $slot, $mode, $okBlock, $doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function pathAccessOk(Context $context, Value $str, int $mode): Value
    {
        $fn = self::ensurePathAccessStandalone($context);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->call($fn, $str, $i32->constInt($mode, false));
    }

    /** @return Value */
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

    /** @return Value */
    public static function pathFilePermsBoxed(Context $context, Value $str): Value
    {
        $mode = self::loadModeOrFail($context, $str);
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_SLT, $mode, $i32->constInt(0, true));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fileperms_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fileperms_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fileperms_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $i64 = $context->getTypeFromString('int64');
        $mode64 = $context->builder->zext($mode, $i64);
        JitValueBox::writeLong($context, $slot, $mode64);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /** @return Value */
    public static function pathFileMtimeBoxed(Context $context, Value $str): Value
    {
        return self::pathStatFieldBoxed($context, $str, self::STAT_MTIME_OFFSET, 'filemtime', true);
    }

    /** @return Value */
    public static function pathFileAtimeBoxed(Context $context, Value $str): Value
    {
        return self::pathStatFieldBoxed($context, $str, self::STAT_ATIME_OFFSET, 'fileatime', true);
    }

    /** @return Value */
    public static function pathFileCtimeBoxed(Context $context, Value $str): Value
    {
        return self::pathStatFieldBoxed($context, $str, self::STAT_CTIME_OFFSET, 'filectime', true);
    }

    /** @return Value */
    public static function pathFileInodeBoxed(Context $context, Value $str): Value
    {
        return self::pathStatFieldBoxed($context, $str, self::STAT_INO_OFFSET, 'fileinode', true);
    }

    /** @return Value */
    public static function pathLinkinfoBoxed(Context $context, Value $str): Value
    {
        return self::pathStatFieldBoxed(
            $context,
            $str,
            self::STAT_DEV_OFFSET,
            'linkinfo',
            true,
            'lstat',
            -1,
            'linkinfo(): No such file or directory'
        );
    }

    /** @return Value */
    public static function pathFileOwnerBoxed(Context $context, Value $str): Value
    {
        return self::pathStatFieldBoxed($context, $str, self::STAT_UID_OFFSET, 'fileowner', false);
    }

    /** @return Value */
    public static function pathFileGroupBoxed(Context $context, Value $str): Value
    {
        return self::pathStatFieldBoxed($context, $str, self::STAT_GID_OFFSET, 'filegroup', false);
    }

    /** @return Value */
    private static function pathStatFieldBoxed(
        Context $context,
        Value $str,
        int $offset,
        string $tag,
        bool $fieldIsI64,
        string $statFn = 'stat',
        ?int $failureLong = null,
        ?string $failureWarning = null
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, $tag.'_stat_buf');
        $i8p = $context->getTypeFromString('int8*');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $ret = $context->builder->call(
            $context->lookupFunction($statFn),
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
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        if (null !== $failureWarning) {
            self::emitStatWarning($context, $failureWarning);
        }
        if (null !== $failureLong) {
            JitValueBox::writeLong($context, $slot, $i64->constInt($failureLong, true));
        } else {
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        }
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $bytePtr = $context->builder->gep($bufPtr, $i64->constInt($offset, false));
        if ($fieldIsI64) {
            $fieldPtr = $context->builder->pointerCast($bytePtr, $i64->pointerType(0));
            $fieldVal = $context->builder->load($fieldPtr);
        } else {
            $fieldPtr = $context->builder->pointerCast($bytePtr, $i32->pointerType(0));
            $fieldVal = $context->builder->zext($context->builder->load($fieldPtr), $i64);
        }
        JitValueBox::writeLong($context, $slot, $fieldVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function emitStatWarning(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    /** @return Value */
    public static function pathDiskFreeSpaceBoxed(Context $context, Value $str): Value
    {
        return self::pathDiskSpaceBoxed($context, $str, self::STATVFS_BAVAIL_OFFSET);
    }

    /** @return Value */
    public static function pathDiskTotalSpaceBoxed(Context $context, Value $str): Value
    {
        return self::pathDiskSpaceBoxed($context, $str, self::STATVFS_BLOCKS_OFFSET);
    }

    private static function pathDiskSpaceBoxed(Context $context, Value $str, int $countOffset): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $bufType = $i8->arrayType(self::STATVFS_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'diskspace_statvfs_buf');
        $i8p = $context->getTypeFromString('int8*');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $ret = $context->builder->call(
            $context->lookupFunction('statvfs'),
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
        $failBlock = BasicBlockHelper::append($context, 'diskspace_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'diskspace_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'diskspace_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $frsizePtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt(self::STATVFS_FRSIZE_OFFSET, false)),
            $i64->pointerType(0)
        );
        $countPtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt($countOffset, false)),
            $i64->pointerType(0)
        );
        $frsize = $context->builder->load($frsizePtr);
        $count = $context->builder->load($countPtr);
        $bytes = $context->builder->mul($count, $frsize);
        JitValueBox::writeLong($context, $slot, $bytes);
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

    private static function modeMatches(Context $context, Value $str, int $fileType, string $statFn = 'stat'): Value
    {
        $mode = self::loadModeOrFail($context, $str, $statFn);
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_SLT, $mode, $i32->constInt(0, true));

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'stat_mode_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'stat_mode_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'stat_mode_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
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

    /** @return Value */
    private static function loadModeOrFail(Context $context, Value $str, string $statFn = 'stat'): Value
    {
        $fn = self::ensureLoadModeStandalone($context, $statFn);

        return $context->builder->call($fn, $str);
    }

    /** Standalone stat/lstat mode helper — avoids LLVM miscompile when {main} mixes getenv(M3_SOURCE) + stat (#8555). */
    private static function ensureLoadModeStandalone(Context $context, string $statFn): Value
    {
        $name = '__phpc_jit_stat_mode_'.$statFn;
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction($name, $existing);

            return $existing;
        }

        StatCacheRuntime::ensureLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $strPtr)
        );
        $entry = $fn->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($entry);

        $str = $fn->getParam(0);
        $isLstat = $i32->constInt('lstat' === $statFn ? 1 : 0, false);
        $mode = $context->builder->call(
            $context->lookupFunction(StatCacheRuntime::FN_MODE_CACHED),
            $str,
            $isLstat
        );
        $context->builder->returnValue($mode);
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function writeFiletypeFromMode(Context $context, Value $slot, Value $mode, BasicBlock $startBlock, BasicBlock $mergeBlock): void
    {
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $masked = $context->builder->and(
            $mode,
            $i32->constInt(self::S_IFMT, false)
        );
        $pairs = [
            [self::S_IFLNK, 'link'],
            [self::S_IFDIR, 'dir'],
            [self::S_IFREG, 'file'],
            [self::S_IFIFO, 'fifo'],
            [self::S_IFCHR, 'char'],
            [self::S_IFBLK, 'block'],
            [self::S_IFSOCK, 'socket'],
        ];
        $id = (string) (++self::$blockSerial);
        $unknown = BasicBlockHelper::append($context, 'filetype_unknown_'.$id);
        $next = $startBlock;
        foreach ($pairs as $index => [$ifmt, $label]) {
            $match = BasicBlockHelper::append($context, 'filetype_match_'.$id.'_'.$index);
            $tail = BasicBlockHelper::append($context, 'filetype_tail_'.$id.'_'.$index);
            $context->builder->positionAtEnd($next);
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $masked,
                $i32->constInt($ifmt, false)
            );
            $context->builder->branchIf($isMatch, $match, $tail);
            $context->builder->positionAtEnd($match);
            $str = $context->builder->load($context->constantStringFromString($label));
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $str
            );
            $context->builder->branch($mergeBlock);
            $next = $tail;
        }
        $context->builder->positionAtEnd($next);
        $context->builder->branch($unknown);
        $context->builder->positionAtEnd($unknown);
        $str = $context->builder->load($context->constantStringFromString('unknown'));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );
        $context->builder->branch($mergeBlock);
    }

    /** Stat mode + uid/gid access check — mirrors VmFsAccessPure (#8990). */
    private static function ensurePathAccessStandalone(Context $context): Value
    {
        $name = '__phpc_jit_path_access_ok';
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction($name, $existing);

            return $existing;
        }

        self::ensureLibcGetuid($context);
        self::ensureLibcGetgid($context);
        $gidInGroups = self::ensureGidInSupplementaryGroupsStandalone($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i1, false, $strPtr, $i32)
        );
        $entry = $fn->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($entry);

        $str = $fn->getParam(0);
        $accessMode = $fn->getParam(1);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $bufType = $i8->arrayType(self::STAT_BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'access_stat_buf');
        $i8p = $context->getTypeFromString('int8*');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $statRet = $context->builder->call(
            $context->lookupFunction('stat'),
            $pathPtr,
            $bufPtr
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i32->constInt(0, false);
        $statFailed = $context->builder->icmp(Builder::INT_NE, $statRet, $zero);
        $failBlock = $fn->appendBasicBlock('stat_fail');
        $okBlock = $fn->appendBasicBlock('stat_ok');
        $context->builder->branchIf($statFailed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->builder->positionAtEnd($okBlock);
        $modePtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt(self::STAT_MODE_OFFSET, false)),
            $i32->pointerType(0)
        );
        $uidPtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt(self::STAT_UID_OFFSET, false)),
            $i32->pointerType(0)
        );
        $gidPtr = $context->builder->pointerCast(
            $context->builder->gep($bufPtr, $i64->constInt(self::STAT_GID_OFFSET, false)),
            $i32->pointerType(0)
        );
        $fileMode = $context->builder->load($modePtr);
        $fileUid = $context->builder->load($uidPtr);
        $fileGid = $context->builder->load($gidPtr);
        $procUid = $context->builder->call($context->lookupFunction('getuid'));
        $procGid = $context->builder->call($context->lookupFunction('getgid'));

        $rmask = $i32->constInt(self::S_IROTH, false);
        $wmask = $i32->constInt(self::S_IWOTH, false);
        $xmask = $i32->constInt(self::S_IXOTH, false);

        $isOwner = $context->builder->icmp(Builder::INT_EQ, $fileUid, $procUid);
        $ownerBlock = $fn->appendBasicBlock('owner_masks');
        $notOwnerBlock = $fn->appendBasicBlock('not_owner');
        $context->builder->branchIf($isOwner, $ownerBlock, $notOwnerBlock);

        $context->builder->positionAtEnd($ownerBlock);
        $ownerRmask = $i32->constInt(self::S_IRUSR, false);
        $ownerWmask = $i32->constInt(self::S_IWUSR, false);
        $ownerXmask = $i32->constInt(self::S_IXUSR, false);
        $afterOwnerBlock = $fn->appendBasicBlock('after_owner');
        $context->builder->branch($afterOwnerBlock);

        $context->builder->positionAtEnd($notOwnerBlock);
        $isPrimaryGroup = $context->builder->icmp(Builder::INT_EQ, $fileGid, $procGid);
        $inSupp = $context->builder->call($gidInGroups, $fileGid);
        $isGroup = $context->builder->or($isPrimaryGroup, $inSupp);
        $groupBlock = $fn->appendBasicBlock('group_masks');
        $otherBlock = $fn->appendBasicBlock('other_masks');
        $context->builder->branchIf($isGroup, $groupBlock, $otherBlock);

        $context->builder->positionAtEnd($groupBlock);
        $groupRmask = $i32->constInt(self::S_IRGRP, false);
        $groupWmask = $i32->constInt(self::S_IWGRP, false);
        $groupXmask = $i32->constInt(self::S_IXGRP, false);
        $context->builder->branch($afterOwnerBlock);

        $context->builder->positionAtEnd($otherBlock);
        $context->builder->branch($afterOwnerBlock);

        $context->builder->positionAtEnd($afterOwnerBlock);
        $phiR = $context->builder->phi($i32);
        $phiR->addIncoming($ownerRmask, $ownerBlock);
        $phiR->addIncoming($groupRmask, $groupBlock);
        $phiR->addIncoming($rmask, $otherBlock);
        $phiW = $context->builder->phi($i32);
        $phiW->addIncoming($ownerWmask, $ownerBlock);
        $phiW->addIncoming($groupWmask, $groupBlock);
        $phiW->addIncoming($wmask, $otherBlock);
        $phiX = $context->builder->phi($i32);
        $phiX->addIncoming($ownerXmask, $ownerBlock);
        $phiX->addIncoming($groupXmask, $groupBlock);
        $phiX->addIncoming($xmask, $otherBlock);

        $rootUid = $i32->constInt(0, false);
        $isRoot = $context->builder->icmp(Builder::INT_EQ, $procUid, $rootUid);
        $rootBlock = $fn->appendBasicBlock('root');
        $nonRootPermBlock = $fn->appendBasicBlock('non_root_perm');
        $permBlock = $fn->appendBasicBlock('perm');
        $context->builder->branchIf($isRoot, $rootBlock, $nonRootPermBlock);

        $context->builder->positionAtEnd($rootBlock);
        $isExecCheck = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($accessMode, $i32->constInt(self::ACCESS_X_OK, false)),
            $zero
        );
        $rootTrueBlock = $fn->appendBasicBlock('root_true');
        $rootExecBlock = $fn->appendBasicBlock('root_exec');
        $context->builder->branchIf($isExecCheck, $rootExecBlock, $rootTrueBlock);
        $context->builder->positionAtEnd($rootTrueBlock);
        $context->builder->returnValue($i1->constInt(1, false));
        $context->builder->positionAtEnd($rootExecBlock);
        $isDir = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($fileMode, $i32->constInt(self::S_IFMT, false)),
            $i32->constInt(self::S_IFDIR, false)
        );
        $anyExecMask = $i32->constInt(self::S_IXANY, false);
        $hasExecBit = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($fileMode, $anyExecMask),
            $zero
        );
        $rootExecAllowed = $context->builder->or($isDir, $hasExecBit);
        $context->builder->returnValue($rootExecAllowed);

        $context->builder->positionAtEnd($nonRootPermBlock);
        $context->builder->branch($permBlock);

        $context->builder->positionAtEnd($permBlock);
        $isRead = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($accessMode, $i32->constInt(self::ACCESS_R_OK, false)),
            $zero
        );
        $isWrite = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($accessMode, $i32->constInt(self::ACCESS_W_OK, false)),
            $zero
        );
        $permMask = $context->builder->select($isRead, $phiR, $context->builder->select($isWrite, $phiW, $phiX));
        $masked = $context->builder->and($fileMode, $permMask);
        $allowed = $context->builder->icmp(Builder::INT_NE, $masked, $zero);
        $context->builder->returnValue($allowed);

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureGidInSupplementaryGroupsStandalone(Context $context): Value
    {
        $name = '__phpc_jit_gid_in_supplementary_groups';
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction($name, $existing);

            return $existing;
        }

        self::ensureLibcGetgroups($context);

        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i1, false, $i32)
        );
        $entry = $fn->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($entry);

        $gid = $fn->getParam(0);
        $count = $context->builder->call(
            $context->lookupFunction('getgroups'),
            $i32->constInt(0, false),
            $context->getTypeFromString('int8*')->constNull()
        );
        $zero = $i32->constInt(0, false);
        $noGroups = $context->builder->icmp(Builder::INT_SLE, $count, $zero);
        $failBlock = $fn->appendBasicBlock('no_groups');
        $allocBlock = $fn->appendBasicBlock('alloc');
        $context->builder->branchIf($noGroups, $failBlock, $allocBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->builder->positionAtEnd($allocBlock);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $gidSize = $i64->constInt(4, false);
        $bytes = $context->builder->mul(
            $context->builder->zext($count, $i64),
            $gidSize
        );
        $list = $context->builder->call(
            $context->lookupFunction('malloc'),
            $bytes
        );
        $ngroups = $context->builder->call(
            $context->lookupFunction('getgroups'),
            $count,
            $list
        );
        $fetchFailed = $context->builder->icmp(Builder::INT_SLE, $ngroups, $zero);
        $loopInitBlock = $fn->appendBasicBlock('loop_init');
        $fetchFailBlock = $fn->appendBasicBlock('fetch_fail');
        $context->builder->branchIf($fetchFailed, $fetchFailBlock, $loopInitBlock);

        $context->builder->positionAtEnd($fetchFailBlock);
        $context->builder->call($context->lookupFunction('free'), $list);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->builder->positionAtEnd($loopInitBlock);
        $loopBlock = $fn->appendBasicBlock('loop');
        $doneBlock = $fn->appendBasicBlock('done');
        $context->builder->branch($loopBlock);

        $context->builder->positionAtEnd($loopBlock);
        $idxPhi = $context->builder->phi($i32);
        $idxPhi->addIncoming($zero, $loopInitBlock);
        $idx = $idxPhi;
        $done = $context->builder->icmp(Builder::INT_SGE, $idx, $ngroups);
        $checkBlock = $fn->appendBasicBlock('check');
        $context->builder->branchIf($done, $doneBlock, $checkBlock);

        $context->builder->positionAtEnd($checkBlock);
        $elemPtr = $context->builder->gep(
            $list,
            $context->builder->mul($context->builder->zext($idx, $i64), $gidSize)
        );
        $elem = $context->builder->load(
            $context->builder->pointerCast($elemPtr, $i32->pointerType(0))
        );
        $match = $context->builder->icmp(Builder::INT_EQ, $elem, $gid);
        $foundBlock = $fn->appendBasicBlock('found');
        $nextBlock = $fn->appendBasicBlock('next');
        $context->builder->branchIf($match, $foundBlock, $nextBlock);

        $context->builder->positionAtEnd($foundBlock);
        $context->builder->call($context->lookupFunction('free'), $list);
        $context->builder->returnValue($i1->constInt(1, false));

        $context->builder->positionAtEnd($nextBlock);
        $nextIdx = $context->builder->add($idx, $i32->constInt(1, false));
        $idxPhi->addIncoming($nextIdx, $nextBlock);
        $context->builder->branch($loopBlock);

        $context->builder->positionAtEnd($doneBlock);
        $context->builder->call($context->lookupFunction('free'), $list);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibcGetuid(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction('getuid');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i32, false);
            $fn = $context->module->addFunction('getuid', $ft);
            $context->registerFunction('getuid', $fn);
        }
    }

    private static function ensureLibcGetgid(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction('getgid');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i32, false);
            $fn = $context->module->addFunction('getgid', $ft);
            $context->registerFunction('getgid', $fn);
        }
    }

    private static function ensureLibcGetgroups(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        try {
            $context->lookupFunction('getgroups');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i32, false, $i32, $i8p);
            $fn = $context->module->addFunction('getgroups', $ft);
            $context->registerFunction('getgroups', $fn);
        }
        try {
            $context->lookupFunction('malloc');
        } catch (\Throwable $e) {
            $i64 = $context->getTypeFromString('int64');
            $ft = $context->context->functionType($i8p, false, $i64);
            $fn = $context->module->addFunction('malloc', $ft);
            $context->registerFunction('malloc', $fn);
        }
        try {
            $context->lookupFunction('free');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($context->getTypeFromString('void'), false, $i8p);
            $fn = $context->module->addFunction('free', $ft);
            $context->registerFunction('free', $fn);
        }
    }
}
