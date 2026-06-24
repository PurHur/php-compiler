<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmFsTempnam;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for remaining phpc_fs_dir.c runtime symbols (#6982).
 */
final class StringFsDirJit
{
    private const PATH_MAX = 4096;

    private const O_WRONLY_CREAT_TRUNC = 577;

    private const PHPC_TYPE_NATIVE_LONG = 1;

    private const PHPC_TYPE_STRING = 4;

    private const AT_FDCWD = -100;

    private const AT_SYMLINK_NOFOLLOW = 0x100;

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
    private const S_IFMT = 0xF000;
    private const S_IFDIR = 0x4000;

    /** Linux glibc x86_64: struct passwd/group uid/gid offset. */
    private const PW_UID_OFFSET = 16;
    private const GR_GID_OFFSET = 16;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_copy',
        '__compiler_resolve_sidecar_source_path',
        '__compiler_touch',
        '__compiler_mkdir',
        '__phpc_stat',
        '__compiler_sys_get_temp_dir',
        '__compiler_tempnam',
        '__compiler_chgrp',
        '__compiler_chown',
        '__compiler_ftok',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_copy');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);

        self::implementIfMissing($context, '__compiler_copy', self::emitCopy(...));
        self::implementIfMissing($context, '__compiler_resolve_sidecar_source_path', self::emitResolveSidecarSourcePath(...));
        self::implementIfMissing($context, '__compiler_touch', self::emitTouch(...));
        self::implementIfMissing($context, '__compiler_mkdir', self::emitMkdir(...));
        self::implementIfMissing($context, '__phpc_stat', self::emitStat(...));
        self::implementIfMissing($context, '__compiler_sys_get_temp_dir', self::emitSysGetTempDir(...));
        self::implementIfMissing($context, '__compiler_tempnam', self::emitTempnam(...));
        self::implementIfMissing($context, '__compiler_chgrp', self::emitChgrp(...));
        self::implementIfMissing($context, '__compiler_chown', self::emitChown(...));
        self::implementIfMissing($context, '__compiler_ftok', self::emitFtok(...));
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        $fn = match ($name) {
            '__compiler_copy' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $strPtr)
            ),
            '__compiler_resolve_sidecar_source_path' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            ),
            '__compiler_touch' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i64, $i64)
            ),
            '__compiler_mkdir' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i64, $i32)
            ),
            '__phpc_stat' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $strPtr, $i32)
            ),
            '__compiler_sys_get_temp_dir' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false)
            ),
            '__compiler_tempnam' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr)
            ),
            '__compiler_chgrp', '__compiler_chown' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $valuePtr, $i32)
            ),
            '__compiler_ftok' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $i32)
            ),
            default => throw new \LogicException('Unknown fs dir JIT function: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');

        $i32 = $context->getTypeFromString('int32');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setLongAt', $voidTy, [$htPtr, $sizeT, $i64]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['__value__readLong', $i64, [$valuePtr]],
            ['__value__readString', $strPtr, [$valuePtr]],
            ['ftok', $i32, [$i8p, $i32]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function stackBytesPtr(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast($slot, $context->getTypeFromString('int8*'));
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

    private static function pathIsDir(Context $context, Value $pathCstr): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $statSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::STAT_BUF_SIZE));
        $statBase = self::stackBytesPtr($context, $statSlot);
        $rc = $context->builder->call($context->lookupFunction('stat'), $pathCstr, $statBase);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));
        $mode = self::statFieldI32ToI64($context, $statBase, self::STAT_MODE_OFFSET);
        $mask = $context->builder->and($mode, $context->getTypeFromString('int64')->constInt(self::S_IFMT, false));
        $isDir = $context->builder->icmp(
            Builder::INT_EQ,
            $mask,
            $context->getTypeFromString('int64')->constInt(self::S_IFDIR, false)
        );

        return $context->builder->and($ok, $isDir);
    }

    private static function mkdirOne(Context $context, Value $path, Value $mode32): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $rc = $context->builder->call($context->lookupFunction('mkdir'), $path, $mode32);
        $created = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));

        return $context->builder->or($created, self::pathIsDir($context, $path));
    }

    private static function emitResolveSidecarSourcePath(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $path = $fn->getParam(0);
        $src = self::stringData($context, $path);
        $exists = $context->builder->call($context->lookupFunction('access'), $src, $i32->constInt(0, false));
        $existsOk = $context->builder->icmp(Builder::INT_EQ, $exists, $i32->constInt(0, false));
        $returnOriginal = $fn->appendBasicBlock('resolve_return_original');
        $tryRemap = $fn->appendBasicBlock('resolve_try_remap');
        $context->builder->branchIf($existsOk, $returnOriginal, $tryRemap);

        $context->builder->positionAtEnd($tryRemap);
        $repoKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_REPO_ROOT'),
            $i8p
        );
        $repo = $context->builder->call($context->lookupFunction('getenv'), $repoKey);
        $repoNull = $context->builder->icmp(Builder::INT_EQ, $repo, $i8p->constNull());
        self::ensureExternal($context, 'strstr', $context->context->functionType($i8p, false, $i8p, $i8p));
        $buildMarker = self::literalCstr($context, '/build/');
        $suffix = $context->builder->call($context->lookupFunction('strstr'), $src, $buildMarker);
        $suffixNull = $context->builder->icmp(Builder::INT_EQ, $suffix, $i8p->constNull());
        $canRemap = $context->builder->and(
            $context->builder->not($repoNull),
            $context->builder->not($suffixNull)
        );
        $returnOriginalFromRemap = $fn->appendBasicBlock('resolve_return_original_from_remap');
        $remap = $fn->appendBasicBlock('resolve_remap');
        $context->builder->branchIf($canRemap, $remap, $returnOriginalFromRemap);

        $context->builder->positionAtEnd($returnOriginalFromRemap);
        $context->builder->branch($returnOriginal);

        $context->builder->positionAtEnd($remap);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PATH_MAX));
        $buf = self::stackBytesPtr($context, $bufSlot);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $buf,
            $sizeT->constInt(self::PATH_MAX, false),
            self::literalCstr($context, '%s%s'),
            $repo,
            $suffix
        );
        $remappedOk = $context->builder->call($context->lookupFunction('access'), $buf, $i32->constInt(0, false));
        $remappedExists = $context->builder->icmp(Builder::INT_EQ, $remappedOk, $i32->constInt(0, false));
        $returnOriginalAfterRemap = $fn->appendBasicBlock('resolve_return_original_after_remap');
        $returnRemapped = $fn->appendBasicBlock('resolve_return_remapped');
        $context->builder->branchIf($remappedExists, $returnRemapped, $returnOriginalAfterRemap);

        $context->builder->positionAtEnd($returnOriginalAfterRemap);
        $context->builder->branch($returnOriginal);

        $context->builder->positionAtEnd($returnRemapped);
        $remappedStr = self::cstrToString($context, $buf);
        $context->builder->returnValue($remappedStr);

        $context->builder->positionAtEnd($returnOriginal);
        $context->builder->returnValue($path);
    }

    private static function emitCopy(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullStr = $strPtr->constNull();
        $nullFile = $i8p->constNull();

        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $from, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $to, $nullStr)
        );
        $fail = $fn->appendBasicBlock('copy_fail');
        $openIn = $fn->appendBasicBlock('copy_open_in');
        $context->builder->branchIf($badArgs, $fail, $openIn);

        $context->builder->positionAtEnd($openIn);
        $src = self::stringData($context, $from);
        $dst = self::stringData($context, $to);
        $in = $context->builder->call($context->lookupFunction('fopen'), $src, self::literalCstr($context, 'rb'));
        $inNull = $context->builder->icmp(Builder::INT_EQ, $in, $nullFile);
        $openOut = $fn->appendBasicBlock('copy_open_out');
        $context->builder->branchIf($inNull, $fail, $openOut);

        $context->builder->positionAtEnd($openOut);
        $out = $context->builder->call($context->lookupFunction('fopen'), $dst, self::literalCstr($context, 'wb'));
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullFile);
        $closeInFail = $fn->appendBasicBlock('copy_close_in_fail');
        $prep = $fn->appendBasicBlock('copy_prep');
        $context->builder->branchIf($outNull, $closeInFail, $prep);

        $context->builder->positionAtEnd($closeInFail);
        $context->builder->call($context->lookupFunction('fclose'), $in);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($prep);
        $okSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($one, $okSlot);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(8192));
        $buf = self::stackBytesPtr($context, $bufSlot);
        $loop = $fn->appendBasicBlock('copy_loop');
        $write = $fn->appendBasicBlock('copy_write');
        $afterRead = $fn->appendBasicBlock('copy_after_read');
        $writeFail = $fn->appendBasicBlock('copy_write_fail');
        $shortTail = $fn->appendBasicBlock('copy_short_tail');
        $setReadErr = $fn->appendBasicBlock('copy_set_read_err');
        $afterLoop = $fn->appendBasicBlock('copy_after_loop');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $n = $context->builder->call(
            $context->lookupFunction('fread'),
            $buf,
            $sizeT->constInt(1, false),
            $sizeT->constInt(8192, false),
            $in
        );
        $hasBytes = $context->builder->icmp(Builder::INT_UGT, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasBytes, $write, $afterRead);

        $context->builder->positionAtEnd($write);
        $written = $context->builder->call(
            $context->lookupFunction('fwrite'),
            $buf,
            $sizeT->constInt(1, false),
            $n,
            $out
        );
        $writeBad = $context->builder->icmp(Builder::INT_NE, $written, $n);
        $context->builder->branchIf($writeBad, $writeFail, $afterRead);

        $context->builder->positionAtEnd($writeFail);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($afterRead);
        $shortRead = $context->builder->icmp(Builder::INT_ULT, $n, $sizeT->constInt(8192, false));
        $context->builder->branchIf($shortRead, $shortTail, $loop);

        $context->builder->positionAtEnd($shortTail);
        $inErr = $context->builder->call($context->lookupFunction('ferror'), $in);
        $hasErr = $context->builder->icmp(Builder::INT_NE, $inErr, $zero);
        $context->builder->branchIf($hasErr, $setReadErr, $afterLoop);

        $context->builder->positionAtEnd($setReadErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($afterLoop);
        $closeOut = $context->builder->call($context->lookupFunction('fclose'), $out);
        $outBad = $context->builder->icmp(Builder::INT_NE, $closeOut, $zero);
        $afterCloseOut = $fn->appendBasicBlock('copy_after_close_out');
        $closeOutErr = $fn->appendBasicBlock('copy_close_out_err');
        $context->builder->branchIf($outBad, $closeOutErr, $afterCloseOut);
        $context->builder->positionAtEnd($closeOutErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterCloseOut);

        $context->builder->positionAtEnd($afterCloseOut);
        $closeIn = $context->builder->call($context->lookupFunction('fclose'), $in);
        $inBad = $context->builder->icmp(Builder::INT_NE, $closeIn, $zero);
        $afterCloseIn = $fn->appendBasicBlock('copy_after_close_in');
        $closeInErr = $fn->appendBasicBlock('copy_close_in_err');
        $context->builder->branchIf($inBad, $closeInErr, $afterCloseIn);
        $context->builder->positionAtEnd($closeInErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterCloseIn);

        $context->builder->positionAtEnd($afterCloseIn);
        $ok = $context->builder->load($okSlot);
        $okBool = $context->builder->icmp(Builder::INT_EQ, $ok, $one);
        $chmodBlock = $fn->appendBasicBlock('copy_chmod');
        $retBlock = $fn->appendBasicBlock('copy_ret');
        $context->builder->branchIf($okBool, $chmodBlock, $retBlock);

        $context->builder->positionAtEnd($chmodBlock);
        $stSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::STAT_BUF_SIZE));
        $stBase = self::stackBytesPtr($context, $stSlot);
        $stRc = $context->builder->call($context->lookupFunction('stat'), $src, $stBase);
        $stOk = $context->builder->icmp(Builder::INT_EQ, $stRc, $zero);
        $chmodTail = $fn->appendBasicBlock('copy_chmod_tail');
        $chmodDo = $fn->appendBasicBlock('copy_chmod_do');
        $context->builder->branchIf($stOk, $chmodDo, $chmodTail);
        $context->builder->positionAtEnd($chmodDo);
        $mode64 = self::statFieldI32ToI64($context, $stBase, self::STAT_MODE_OFFSET);
        $context->builder->call($context->lookupFunction('chmod'), $dst, $context->builder->truncOrBitCast($mode64, $i32));
        $context->builder->branch($chmodTail);
        $context->builder->positionAtEnd($chmodTail);
        $context->builder->branch($retBlock);

        $context->builder->positionAtEnd($retBlock);
        $context->builder->returnValue($context->builder->load($okSlot));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero);
    }

    private static function emitTouch(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        $path = $fn->getParam(0);
        $mtime = $fn->getParam(1);
        $atime = $fn->getParam(2);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $fail = $fn->appendBasicBlock('touch_fail');
        $checkPath = $fn->appendBasicBlock('touch_check_path');
        $context->builder->branchIf($isNull, $fail, $checkPath);

        $context->builder->positionAtEnd($checkPath);
        $p = self::stringData($context, $path);
        $stSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::STAT_BUF_SIZE));
        $stBase = self::stackBytesPtr($context, $stSlot);
        $stRc = $context->builder->call($context->lookupFunction('stat'), $p, $stBase);
        $needCreate = $context->builder->icmp(Builder::INT_NE, $stRc, $zero);
        $openBlock = $fn->appendBasicBlock('touch_open');
        $afterOpen = $fn->appendBasicBlock('touch_after_open');
        $context->builder->branchIf($needCreate, $openBlock, $afterOpen);

        $context->builder->positionAtEnd($openBlock);
        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $p,
            $i32->constInt(self::O_WRONLY_CREAT_TRUNC, false),
            $i32->constInt(0666, false)
        );
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero);
        $closeBlock = $fn->appendBasicBlock('touch_close_fd');
        $context->builder->branchIf($fdBad, $fail, $closeBlock);
        $context->builder->positionAtEnd($closeBlock);
        $closeRc = $context->builder->call($context->lookupFunction('close'), $fd);
        $closeBad = $context->builder->icmp(Builder::INT_NE, $closeRc, $zero);
        $context->builder->branchIf($closeBad, $fail, $afterOpen);

        $context->builder->positionAtEnd($afterOpen);
        $mtimeNeg = $context->builder->icmp(Builder::INT_SLT, $mtime, $i64->constInt(0, true));
        $atimeNeg = $context->builder->icmp(Builder::INT_SLT, $atime, $i64->constInt(0, true));
        $bothNeg = $context->builder->and($mtimeNeg, $atimeNeg);
        $utimeNow = $fn->appendBasicBlock('touch_utime_now');
        $custom = $fn->appendBasicBlock('touch_custom');
        $context->builder->branchIf($bothNeg, $utimeNow, $custom);

        $context->builder->positionAtEnd($utimeNow);
        $utNowRc = $context->builder->call($context->lookupFunction('utime'), $p, $i8p->constNull());
        $utNowOk = $context->builder->icmp(Builder::INT_EQ, $utNowRc, $zero);
        $context->builder->returnValue($context->builder->select($utNowOk, $one, $zero));

        $context->builder->positionAtEnd($custom);
        $now = $context->builder->call($context->lookupFunction('time'), $context->getTypeFromString('int8*')->constNull());
        $mtimeEff = $context->builder->select($mtimeNeg, $now, $mtime);
        $atimeEff = $context->builder->select($atimeNeg, $mtimeEff, $atime);
        $times = BasicBlockHelper::entryAlloca($context, $i64->arrayType(2));
        $context->builder->store($atimeEff, $context->builder->inBoundsGEP($times, $i32->constInt(0, false), $i64->constInt(0, false)));
        $context->builder->store($mtimeEff, $context->builder->inBoundsGEP($times, $i32->constInt(0, false), $i64->constInt(1, false)));
        $utRc = $context->builder->call(
            $context->lookupFunction('utime'),
            $p,
            self::stackBytesPtr($context, $times)
        );
        $utOk = $context->builder->icmp(Builder::INT_EQ, $utRc, $zero);
        $context->builder->returnValue($context->builder->select($utOk, $one, $zero));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero);
    }

    private static function emitMkdir(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $path = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $recursive = $fn->getParam(2);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $mode32 = $context->builder->truncOrBitCast($mode, $i32);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $fail = $fn->appendBasicBlock('mkdir_fail');
        $body = $fn->appendBasicBlock('mkdir_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $p = self::stringData($context, $path);
        $nonRec = $context->builder->icmp(Builder::INT_EQ, $recursive, $zero);
        $simple = $fn->appendBasicBlock('mkdir_simple');
        $rec = $fn->appendBasicBlock('mkdir_recursive');
        $context->builder->branchIf($nonRec, $simple, $rec);

        $context->builder->positionAtEnd($simple);
        $mkRc = $context->builder->call($context->lookupFunction('mkdir'), $p, $mode32);
        $mkOk = $context->builder->icmp(Builder::INT_EQ, $mkRc, $zero);
        $context->builder->returnValue($context->builder->select($mkOk, $one, $zero));

        $context->builder->positionAtEnd($rec);
        $isDir = self::pathIsDir($context, $p);
        $recCheckLen = $fn->appendBasicBlock('mkdir_rec_check_len');
        $context->builder->branchIf($isDir, $fail, $recCheckLen);

        $context->builder->positionAtEnd($recCheckLen);
        $len = $context->builder->call($context->lookupFunction('strlen'), $p);
        $tooLong = $context->builder->icmp(Builder::INT_UGE, $len, $sizeT->constInt(self::PATH_MAX, false));
        $copyBlock = $fn->appendBasicBlock('mkdir_rec_copy');
        $context->builder->branchIf($tooLong, $fail, $copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PATH_MAX));
        $buf = $context->builder->pointerCast($bufSlot, $context->getTypeFromString('int8*'));
        $copyLen = $context->builder->add($len, $sizeT->constInt(1, false));
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $buf,
            $p,
            $copyLen
        );
        $lenGt1 = $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(1, false));
        $trimBlock = $fn->appendBasicBlock('mkdir_trim');
        $loopInit = $fn->appendBasicBlock('mkdir_loop_init');
        $context->builder->branchIf($lenGt1, $trimBlock, $loopInit);

        $context->builder->positionAtEnd($trimBlock);
        $lastIx = $context->builder->sub($len, $sizeT->constInt(1, false));
        $lastPtr = $context->builder->inBoundsGEP($buf, $lastIx);
        $isSlash = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($lastPtr),
            $i8->constInt(ord('/'), false)
        );
        $trimDo = $fn->appendBasicBlock('mkdir_trim_do');
        $trimDone = $fn->appendBasicBlock('mkdir_trim_done');
        $context->builder->branchIf($isSlash, $trimDo, $trimDone);
        $context->builder->positionAtEnd($trimDo);
        $context->builder->store($i8->constInt(0, false), $lastPtr);
        $context->builder->branch($trimDone);
        $context->builder->positionAtEnd($trimDone);
        $context->builder->branch($loopInit);

        $context->builder->positionAtEnd($loopInit);
        $pSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8*'));
        $context->builder->store(
            $context->builder->inBoundsGEP($buf, $sizeT->constInt(1, false)),
            $pSlot
        );
        $loopHead = $fn->appendBasicBlock('mkdir_loop_head');
        $loopBody = $fn->appendBasicBlock('mkdir_loop_body');
        $afterLoop = $fn->appendBasicBlock('mkdir_after_loop');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $cur = $context->builder->load($pSlot);
        $ch = $context->builder->load($cur);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $context->builder->branchIf($atEnd, $afterLoop, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $slash = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('/'), false));
        $step = $fn->appendBasicBlock('mkdir_step');
        $maybeMk = $fn->appendBasicBlock('mkdir_maybe_mk');
        $context->builder->branchIf($slash, $maybeMk, $step);

        $context->builder->positionAtEnd($maybeMk);
        $context->builder->store($i8->constInt(0, false), $cur);
        $first = $context->builder->load($buf);
        $firstNotEmpty = $context->builder->icmp(Builder::INT_NE, $first, $i8->constInt(0, false));
        $mkDo = $fn->appendBasicBlock('mkdir_do');
        $mkSkip = $fn->appendBasicBlock('mkdir_skip');
        $context->builder->branchIf($firstNotEmpty, $mkDo, $mkSkip);
        $context->builder->positionAtEnd($mkDo);
        $ok = self::mkdirOne($context, $buf, $mode32);
        $context->builder->branchIf($ok, $mkSkip, $fail);
        $context->builder->positionAtEnd($mkSkip);
        $context->builder->store($i8->constInt(ord('/'), false), $cur);
        $context->builder->branch($step);

        $context->builder->positionAtEnd($step);
        $next = $context->builder->inBoundsGEP($cur, $sizeT->constInt(1, false));
        $context->builder->store($next, $pSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($afterLoop);
        $lastOk = self::mkdirOne($context, $buf, $mode32);
        $context->builder->returnValue($context->builder->select($lastOk, $one, $zero));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero);
    }

    private static function statPair(Context $context, Value $ht, int $index, string $key, Value $value): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            self::cstrToString($context, self::literalCstr($context, $key)),
            $value
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $sizeT->constInt($index, false),
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
        self::statPair($context, $ht, 0, 'dev', self::statFieldI64($context, $stBase, self::STAT_DEV_OFFSET));
        self::statPair($context, $ht, 1, 'ino', self::statFieldI64($context, $stBase, self::STAT_INO_OFFSET));
        self::statPair($context, $ht, 2, 'mode', self::statFieldI32ToI64($context, $stBase, self::STAT_MODE_OFFSET));
        self::statPair($context, $ht, 3, 'nlink', self::statFieldI64($context, $stBase, self::STAT_NLINK_OFFSET));
        self::statPair($context, $ht, 4, 'uid', self::statFieldI32ToI64($context, $stBase, self::STAT_UID_OFFSET));
        self::statPair($context, $ht, 5, 'gid', self::statFieldI32ToI64($context, $stBase, self::STAT_GID_OFFSET));
        self::statPair($context, $ht, 6, 'rdev', self::statFieldI64($context, $stBase, self::STAT_RDEV_OFFSET));
        self::statPair($context, $ht, 7, 'size', self::statFieldI64($context, $stBase, self::STAT_SIZE_OFFSET));
        self::statPair($context, $ht, 8, 'atime', self::statFieldI64($context, $stBase, self::STAT_ATIME_OFFSET));
        self::statPair($context, $ht, 9, 'mtime', self::statFieldI64($context, $stBase, self::STAT_MTIME_OFFSET));
        self::statPair($context, $ht, 10, 'ctime', self::statFieldI64($context, $stBase, self::STAT_CTIME_OFFSET));
        self::statPair($context, $ht, 11, 'blksize', self::statFieldI64($context, $stBase, self::STAT_BLKSIZE_OFFSET));
        self::statPair($context, $ht, 12, 'blocks', self::statFieldI64($context, $stBase, self::STAT_BLOCKS_OFFSET));
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullHt);
    }

    private static function emitSysGetTempDir(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $dirSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store(self::literalCstr($context, '/tmp'), $dirSlot);

        $checkTmpdir = $fn->appendBasicBlock('tmpdir_check_tmpdir');
        $checkTmpdirEmpty = $fn->appendBasicBlock('tmpdir_check_tmpdir_empty');
        $checkTemp = $fn->appendBasicBlock('tmpdir_check_temp');
        $checkTempEmpty = $fn->appendBasicBlock('tmpdir_check_temp_empty');
        $checkTmp = $fn->appendBasicBlock('tmpdir_check_tmp');
        $checkTmpEmpty = $fn->appendBasicBlock('tmpdir_check_tmp_empty');
        $resolve = $fn->appendBasicBlock('tmpdir_resolve');
        $context->builder->branch($checkTmpdir);

        $context->builder->positionAtEnd($checkTmpdir);
        $tmpdir = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'TMPDIR'));
        $tmpdirOk = $context->builder->icmp(Builder::INT_NE, $tmpdir, $i8p->constNull());
        $useTmpdir = $fn->appendBasicBlock('tmpdir_use_tmpdir');
        $context->builder->branchIf($tmpdirOk, $checkTmpdirEmpty, $checkTemp);
        $context->builder->positionAtEnd($checkTmpdirEmpty);
        $tmpdirNotEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($tmpdir), $i8->constInt(0, false));
        $context->builder->branchIf($tmpdirNotEmpty, $useTmpdir, $checkTemp);
        $context->builder->positionAtEnd($useTmpdir);
        $context->builder->store($tmpdir, $dirSlot);
        $context->builder->branch($resolve);

        $context->builder->positionAtEnd($checkTemp);
        $temp = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'TEMP'));
        $tempOk = $context->builder->icmp(Builder::INT_NE, $temp, $i8p->constNull());
        $useTemp = $fn->appendBasicBlock('tmpdir_use_temp');
        $context->builder->branchIf($tempOk, $checkTempEmpty, $checkTmp);
        $context->builder->positionAtEnd($checkTempEmpty);
        $tempNotEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($temp), $i8->constInt(0, false));
        $context->builder->branchIf($tempNotEmpty, $useTemp, $checkTmp);
        $context->builder->positionAtEnd($useTemp);
        $context->builder->store($temp, $dirSlot);
        $context->builder->branch($resolve);

        $context->builder->positionAtEnd($checkTmp);
        $tmp = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'TMP'));
        $tmpOk = $context->builder->icmp(Builder::INT_NE, $tmp, $i8p->constNull());
        $useTmp = $fn->appendBasicBlock('tmpdir_use_tmp');
        $context->builder->branchIf($tmpOk, $checkTmpEmpty, $resolve);
        $context->builder->positionAtEnd($checkTmpEmpty);
        $tmpNotEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($tmp), $i8->constInt(0, false));
        $context->builder->branchIf($tmpNotEmpty, $useTmp, $resolve);
        $context->builder->positionAtEnd($useTmp);
        $context->builder->store($tmp, $dirSlot);
        $context->builder->branch($resolve);

        $context->builder->positionAtEnd($resolve);
        $resolvedSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PATH_MAX));
        $resolved = $context->builder->pointerCast($resolvedSlot, $i8p);
        $useDir = $context->builder->load($dirSlot);
        $real = $context->builder->call($context->lookupFunction('realpath'), $useDir, $resolved);
        $hasReal = $context->builder->icmp(Builder::INT_NE, $real, $i8p->constNull());
        $retReal = $fn->appendBasicBlock('tmpdir_ret_real');
        $retDir = $fn->appendBasicBlock('tmpdir_ret_dir');
        $context->builder->branchIf($hasReal, $retReal, $retDir);

        $context->builder->positionAtEnd($retReal);
        $context->builder->returnValue(self::cstrToString($context, $resolved));

        $context->builder->positionAtEnd($retDir);
        $context->builder->returnValue(self::cstrToString($context, $useDir));
    }

    private static function emitTempnam(Context $context, LlvmFunction $fn): void
    {
        TypeErrorRaise::ensureLinked($context);
        StringTriggerError::ensureLinked($context);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $strMap = $context->structFieldMap['__string__'];

        foreach ([
            ['memchr', $i8p, [$i8p, $i32, $sizeT]],
            ['strrchr', $i8p, [$i8p, $i32]],
            ['strlen', $i64, [$i8p]],
            ['memcpy', $i8p, [$i8p, $i8p, $sizeT]],
            ['snprintf', $i32, [$i8p, $sizeT, $i8p]],
            ['mkstemp', $i32, [$i8p]],
            ['close', $i32, [$i32]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }

        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $dirObj = $fn->getParam(0);
        $pfxObj = $fn->getParam(1);
        $nullStr = $strPtr->constNull();
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $dirObj, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $pfxObj, $nullStr)
        );
        $fail = $fn->appendBasicBlock('tempnam_fail');
        $body = $fn->appendBasicBlock('tempnam_body');
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $dir = self::stringData($context, $dirObj);
        $pfx = self::stringData($context, $pfxObj);
        $dirLen = $context->builder->load($context->builder->structGep($dirObj, $strMap['length']));
        $pfxLen = $context->builder->load($context->builder->structGep($pfxObj, $strMap['length']));

        self::emitTempnamRejectNullByte(
            $context,
            $fn,
            $dir,
            $dirLen,
            'tempnam(): Argument #1 ($directory) must not contain any null bytes'
        );
        self::emitTempnamRejectNullByte(
            $context,
            $fn,
            $pfx,
            $pfxLen,
            'tempnam(): Argument #2 ($prefix) must not contain any null bytes'
        );

        $dirEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($dir), $i8->constInt(0, false));
        $normBlock = $fn->appendBasicBlock('tempnam_norm');
        $context->builder->branchIf($dirEmpty, $fail, $normBlock);

        $context->builder->positionAtEnd($normBlock);
        $pfxBufSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(64));
        $pfxBuf = $context->builder->pointerCast($pfxBufSlot, $i8p);
        $startSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $lastSepSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($i8p->constNull(), $lastSepSlot);

        $slash = $context->builder->call($context->lookupFunction('strrchr'), $pfx, $i32->constInt(ord('/'), false));
        $bslash = $context->builder->call($context->lookupFunction('strrchr'), $pfx, $i32->constInt(ord('\\'), false));
        $slashNull = $context->builder->icmp(Builder::INT_EQ, $slash, $i8p->constNull());
        $bslashNull = $context->builder->icmp(Builder::INT_EQ, $bslash, $i8p->constNull());

        $afterSlash = $fn->appendBasicBlock('tempnam_after_slash');
        $slashSet = $fn->appendBasicBlock('tempnam_slash_set');
        $context->builder->branchIf($slashNull, $afterSlash, $slashSet);
        $context->builder->positionAtEnd($slashSet);
        $context->builder->store($slash, $lastSepSlot);
        $context->builder->branch($afterSlash);
        $context->builder->positionAtEnd($afterSlash);

        $afterBslash = $fn->appendBasicBlock('tempnam_after_bslash');
        $bslashCheck = $fn->appendBasicBlock('tempnam_bslash_check');
        $bslashSet = $fn->appendBasicBlock('tempnam_bslash_set');
        $context->builder->branchIf($bslashNull, $afterBslash, $bslashCheck);
        $context->builder->positionAtEnd($bslashCheck);
        $lastSep = $context->builder->load($lastSepSlot);
        $lastSepNull = $context->builder->icmp(Builder::INT_EQ, $lastSep, $i8p->constNull());
        $bslashGt = $context->builder->icmp(Builder::INT_UGT, $bslash, $lastSep);
        $context->builder->branchIf(
            $context->builder->or($lastSepNull, $bslashGt),
            $bslashSet,
            $afterBslash
        );
        $context->builder->positionAtEnd($bslashSet);
        $context->builder->store($bslash, $lastSepSlot);
        $context->builder->branch($afterBslash);
        $context->builder->positionAtEnd($afterBslash);

        $copyBlock = $fn->appendBasicBlock('tempnam_copy_prefix');
        $usePfxStart = $fn->appendBasicBlock('tempnam_use_pfx_start');
        $useLastStart = $fn->appendBasicBlock('tempnam_use_last_start');
        $lastSep = $context->builder->load($lastSepSlot);
        $lastSepNull = $context->builder->icmp(Builder::INT_EQ, $lastSep, $i8p->constNull());
        $context->builder->branchIf($lastSepNull, $usePfxStart, $useLastStart);
        $context->builder->positionAtEnd($usePfxStart);
        $context->builder->store($pfx, $startSlot);
        $context->builder->branch($copyBlock);
        $context->builder->positionAtEnd($useLastStart);
        $context->builder->store($context->builder->gep($lastSep, $i64->constInt(1, false)), $startSlot);
        $context->builder->branch($copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $start = $context->builder->load($startSlot);
        $baseLen = $context->builder->call($context->lookupFunction('strlen'), $start);
        $maxCopy = $sizeT->constInt(63, false);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $baseLen, $maxCopy),
            $context->builder->intCast($baseLen, $sizeT),
            $maxCopy
        );
        $context->builder->call($context->lookupFunction('memcpy'), $pfxBuf, $start, $copyLen);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->gep($pfxBuf, $context->builder->intCast($copyLen, $i64))
        );

        $tryPrimary = $fn->appendBasicBlock('tempnam_try_primary');
        $context->builder->branch($tryPrimary);
        $context->builder->positionAtEnd($tryPrimary);
        $tplSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PATH_MAX));
        $tpl = $context->builder->pointerCast($tplSlot, $i8p);
        $primaryOk = self::emitTempnamMkstempAttempt($context, $fn, $dir, $pfxBuf, $tpl, 'tempnam_primary');
        $retPrimary = $fn->appendBasicBlock('tempnam_ret_primary');
        $fallback = $fn->appendBasicBlock('tempnam_fallback');
        $context->builder->branchIf($primaryOk, $retPrimary, $fallback);

        $context->builder->positionAtEnd($retPrimary);
        $context->builder->returnValue(self::cstrToString($context, $tpl));

        $context->builder->positionAtEnd($fallback);
        self::emitTempnamNotice($context);
        $fallbackDir = $context->builder->call($context->lookupFunction('__compiler_sys_get_temp_dir'));
        $fallbackNull = $context->builder->icmp(Builder::INT_EQ, $fallbackDir, $nullStr);
        $tryFallback = $fn->appendBasicBlock('tempnam_try_fallback');
        $context->builder->branchIf($fallbackNull, $fail, $tryFallback);
        $context->builder->positionAtEnd($tryFallback);
        $fallbackData = self::stringData($context, $fallbackDir);
        $fallbackOk = self::emitTempnamMkstempAttempt($context, $fn, $fallbackData, $pfxBuf, $tpl, 'tempnam_fb');
        $retFallback = $fn->appendBasicBlock('tempnam_ret_fallback');
        $context->builder->branchIf($fallbackOk, $retFallback, $fail);
        $context->builder->positionAtEnd($retFallback);
        $context->builder->returnValue(self::cstrToString($context, $tpl));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullStr);
    }

    private static function emitTempnamRejectNullByte(
        Context $context,
        LlvmFunction $fn,
        Value $data,
        Value $len,
        string $message
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $found = $context->builder->call(
            $context->lookupFunction('memchr'),
            $data,
            $i32->constInt(0, false),
            $context->builder->intCast($len, $sizeT)
        );
        $hasNull = $context->builder->icmp(Builder::INT_NE, $found, $i8p->constNull());
        static $rejectSeq = 0;
        $tag = 'tempnam_nul_'.(string) (++$rejectSeq);
        $ok = $fn->appendBasicBlock($tag.'_ok');
        $bad = $fn->appendBasicBlock($tag.'_bad');
        $context->builder->branchIf($hasNull, $bad, $ok);
        $context->builder->positionAtEnd($bad);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($ok);
    }

    private static function emitTempnamNotice(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $message = VmFsTempnam::NOTICE_MESSAGE;
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(8, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function emitTempnamMkstempAttempt(
        Context $context,
        LlvmFunction $fn,
        Value $dir,
        Value $pfxBuf,
        Value $tpl,
        string $tag
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $format = $fn->appendBasicBlock($tag.'_format');
        $mkstempBb = $fn->appendBasicBlock($tag.'_mkstemp');
        $closeBb = $fn->appendBasicBlock($tag.'_close');
        $failBb = $fn->appendBasicBlock($tag.'_fail');
        $doneBb = $fn->appendBasicBlock($tag.'_done');

        $context->builder->branch($format);
        $context->builder->positionAtEnd($format);
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $tpl,
            $sizeT->constInt(self::PATH_MAX, false),
            self::literalCstr($context, '%s/%sXXXXXX'),
            $dir,
            $pfxBuf
        );
        $tooLong = $context->builder->icmp(Builder::INT_SGE, $n, $i32->constInt(self::PATH_MAX, false));
        $context->builder->branchIf($tooLong, $failBb, $mkstempBb);

        $context->builder->positionAtEnd($mkstempBb);
        $fd = $context->builder->call($context->lookupFunction('mkstemp'), $tpl);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $i32->constInt(0, true));
        $context->builder->branchIf($fdBad, $failBb, $closeBb);

        $context->builder->positionAtEnd($closeBb);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $i1 = $context->getTypeFromString('int1');
        $okPhi = $context->builder->phi($i1, $tag.'_ok');
        $okPhi->addIncoming($i1->constInt(0, false), $failBb);
        $okPhi->addIncoming($i1->constInt(1, false), $closeBb);

        return $okPhi;
    }

    private static function resolveIdFromValue(Context $context, Value $value, bool $group): Value
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

        $idSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(-1, true), $idSlot);
        $longBlock = BasicBlockHelper::append($context, ($group ? 'gid' : 'uid').'_long');
        $strBlock = BasicBlockHelper::append($context, ($group ? 'gid' : 'uid').'_str');
        $done = BasicBlockHelper::append($context, ($group ? 'gid' : 'uid').'_done');
        $next = BasicBlockHelper::append($context, ($group ? 'gid' : 'uid').'_next');
        $context->builder->branchIf($isLong, $longBlock, $next);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->store($context->builder->call($context->lookupFunction('__value__readLong'), $value), $idSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($next);
        $context->builder->branchIf($isStr, $strBlock, $done);

        $context->builder->positionAtEnd($strBlock);
        $strObj = $context->builder->call($context->lookupFunction('__value__readString'), $value);
        $c = self::stringData($context, $strObj);
        $endSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $parsed = $context->builder->call($context->lookupFunction('strtol'), $c, $endSlot, $i32->constInt(10, false));
        $end = $context->builder->load($endSlot);
        $endZero = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($end), $i8->constInt(0, false));
        $endMoved = $context->builder->icmp(Builder::INT_NE, $end, $c);
        $numeric = $context->builder->and($endZero, $endMoved);
        $fromLookup = BasicBlockHelper::append($context, ($group ? 'gid' : 'uid').'_lookup');
        $parsedBlock = BasicBlockHelper::append($context, ($group ? 'gid' : 'uid').'_parsed');
        $context->builder->branchIf($numeric, $parsedBlock, $fromLookup);
        $context->builder->positionAtEnd($parsedBlock);
        $context->builder->store($parsed, $idSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($fromLookup);
        $entry = $context->builder->call($context->lookupFunction($group ? 'getgrnam' : 'getpwnam'), $c);
        $found = $context->builder->icmp(Builder::INT_NE, $entry, $i8p->constNull());
        $lookupDone = BasicBlockHelper::append($context, ($group ? 'gid' : 'uid').'_lookup_done');
        $lookupSet = BasicBlockHelper::append($context, ($group ? 'gid' : 'uid').'_lookup_set');
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

    private static function emitFtok(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $pathObj = $fn->getParam(0);
        $projId = $fn->getParam(1);
        $nullStr = $strPtr->constNull();
        $minusOneI32 = $i32->constInt(-1, true);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $pathObj, $nullStr);
        $fail = $fn->appendBasicBlock('ftok_fail');
        $body = $fn->appendBasicBlock('ftok_body');
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $path = self::stringData($context, $pathObj);
        $key = $context->builder->call($context->lookupFunction('ftok'), $path, $projId);
        $context->builder->returnValue($context->builder->sext($key, $i64));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($context->builder->sext($minusOneI32, $i64));
    }

    private static function emitChgrp(Context $context, LlvmFunction $fn): void
    {
        self::emitChx($context, $fn, true);
    }

    private static function emitChown(Context $context, LlvmFunction $fn): void
    {
        self::emitChx($context, $fn, false);
    }

    private static function emitChx(Context $context, LlvmFunction $fn, bool $group): void
    {
        $entry = $fn->appendBasicBlock('entry');
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
        $fail = $fn->appendBasicBlock('chx_fail');
        $body = $fn->appendBasicBlock('chx_body');
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $p = self::stringData($context, $path);
        $id = self::resolveIdFromValue($context, $idValue, $group);
        $idBad = $context->builder->icmp(Builder::INT_EQ, $id, $i64->constInt(-1, true));
        $syscall = $fn->appendBasicBlock('chx_syscall');
        $context->builder->branchIf($idBad, $fail, $syscall);

        $context->builder->positionAtEnd($syscall);
        $isLch = $context->builder->icmp(Builder::INT_NE, $lchFlag, $zero);
        $doAt = $fn->appendBasicBlock('chx_do_at');
        $doPlain = $fn->appendBasicBlock('chx_do_plain');
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFsDirJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
