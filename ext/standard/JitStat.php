<?php

declare(strict_types=1);

/**
 * LLVM JIT helpers for file_exists(), is_*(), and filestat builtins via PHP helpers (#9112).
 *
 * VM SSOT: {@see VmStatPath}, {@see VmStatCache}, {@see VmFsDiskNative}
 * php-src: ext/standard/filestat.c
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StatPathRuntime;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStat
{
    private static int $blockSerial = 0;

    public static function pathExists(Context $context, Value $str): Value
    {
        return self::callPathPredicate($context, $str, StatPathRuntime::FN_EXISTS);
    }

    public static function pathIsFile(Context $context, Value $str): Value
    {
        return self::callPathPredicate($context, $str, StatPathRuntime::FN_IS_FILE);
    }

    public static function pathIsDir(Context $context, Value $str): Value
    {
        return self::callPathPredicate($context, $str, StatPathRuntime::FN_IS_DIR);
    }

    public static function pathIsLink(Context $context, Value $str): Value
    {
        return self::callPathPredicate($context, $str, StatPathRuntime::FN_IS_LINK);
    }

    public static function pathIsReadable(Context $context, Value $str): Value
    {
        return self::callPathPredicate($context, $str, StatPathRuntime::FN_IS_READABLE);
    }

    public static function pathIsWritable(Context $context, Value $str): Value
    {
        return self::callPathPredicate($context, $str, StatPathRuntime::FN_IS_WRITABLE);
    }

    public static function pathIsExecutable(Context $context, Value $str): Value
    {
        return self::callPathPredicate($context, $str, StatPathRuntime::FN_IS_EXECUTABLE);
    }

    /** @return Value */
    public static function pathFiletypeBoxed(Context $context, Value $str): Value
    {
        StringTriggerErrorJit::implement($context);
        StatPathRuntime::ensureLinked($context);

        $label = $context->builder->call(
            $context->lookupFunction(StatPathRuntime::FN_FILETYPE_LABEL),
            $str
        );
        $map = $context->structFieldMap['__string__'];
        $lenPtr = $context->builder->structGep($label, $map['length']);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->load($lenPtr);
        $failed = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'filetype_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'filetype_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'filetype_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitPathStatFailureWarning($context, $str, 'filetype', true);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $label
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /** @return Value */
    public static function pathFileSizeBoxed(Context $context, Value $str): Value
    {
        StringTriggerErrorJit::implement($context);

        return self::pathLongFieldBoxed(
            $context,
            $str,
            StatFieldsJitHelper::FIELD_SIZE,
            'filesize',
            false,
            true
        );
    }

    /** @return Value */
    public static function pathFilePermsBoxed(Context $context, Value $str): Value
    {
        return self::pathLongFieldBoxed(
            $context,
            $str,
            StatFieldsJitHelper::FIELD_MODE,
            'fileperms',
            false,
            false
        );
    }

    /** @return Value */
    public static function pathFileMtimeBoxed(Context $context, Value $str): Value
    {
        return self::pathLongFieldBoxed($context, $str, StatFieldsJitHelper::FIELD_MTIME, 'filemtime', false, true);
    }

    /** @return Value */
    public static function pathFileAtimeBoxed(Context $context, Value $str): Value
    {
        return self::pathLongFieldBoxed($context, $str, StatFieldsJitHelper::FIELD_ATIME, 'fileatime', false, true);
    }

    /** @return Value */
    public static function pathFileCtimeBoxed(Context $context, Value $str): Value
    {
        return self::pathLongFieldBoxed($context, $str, StatFieldsJitHelper::FIELD_CTIME, 'filectime', false, true);
    }

    /** @return Value */
    public static function pathFileInodeBoxed(Context $context, Value $str): Value
    {
        return self::pathLongFieldBoxed($context, $str, StatFieldsJitHelper::FIELD_INO, 'fileinode', false, true);
    }

    /** @return Value */
    public static function pathLinkinfoBoxed(Context $context, Value $str): Value
    {
        return self::pathLongFieldBoxed(
            $context,
            $str,
            StatFieldsJitHelper::FIELD_DEV,
            'linkinfo',
            true,
            true,
            -1,
            'linkinfo(): No such file or directory'
        );
    }

    /** @return Value */
    public static function pathFileOwnerBoxed(Context $context, Value $str): Value
    {
        return self::pathLongFieldBoxed($context, $str, StatFieldsJitHelper::FIELD_UID, 'fileowner', false, true);
    }

    /** @return Value */
    public static function pathFileGroupBoxed(Context $context, Value $str): Value
    {
        return self::pathLongFieldBoxed($context, $str, StatFieldsJitHelper::FIELD_GID, 'filegroup', false, true);
    }

    /** @return Value */
    public static function pathDiskFreeSpaceBoxed(Context $context, Value $str): Value
    {
        return self::pathDiskSpaceBoxed($context, $str, StatPathRuntime::FN_DISK_FREE);
    }

    /** @return Value */
    public static function pathDiskTotalSpaceBoxed(Context $context, Value $str): Value
    {
        return self::pathDiskSpaceBoxed($context, $str, StatPathRuntime::FN_DISK_TOTAL);
    }

    public static function warnPathStatArrayFailed(
        Context $context,
        Value $pathStr,
        string $function,
        bool $lstat
    ): void {
        self::emitPathStatFailureWarning($context, $pathStr, $function, $lstat);
    }

    private static function callPathPredicate(Context $context, Value $str, string $abiName): Value
    {
        StatPathRuntime::ensureLinked($context);

        return $context->builder->call($context->lookupFunction($abiName), $str);
    }

    /** @return Value */
    private static function pathLongFieldBoxed(
        Context $context,
        Value $str,
        int $fieldId,
        string $tag,
        bool $lstat,
        bool $warnOnFailure,
        ?int $failureLong = null,
        ?string $failureWarning = null
    ): Value {
        StatPathRuntime::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $field = $context->builder->call(
            $context->lookupFunction(StatPathRuntime::FN_LONG_FIELD),
            $str,
            $i64->constInt($lstat ? 1 : 0, false),
            $i64->constInt($fieldId, false)
        );
        $failed = $context->builder->icmp(Builder::INT_SLT, $field, $i64->constInt(0, true));

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
        } elseif ($warnOnFailure) {
            self::emitPathStatFailureWarning($context, $str, $tag, $lstat);
        }
        if (null !== $failureLong) {
            JitValueBox::writeLong($context, $slot, $i64->constInt($failureLong, true));
        } else {
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        }
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $field);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /** @return Value */
    private static function pathDiskSpaceBoxed(Context $context, Value $str, string $abiName): Value
    {
        StringTriggerErrorJit::implement($context);
        StatPathRuntime::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $bytes = $context->builder->call($context->lookupFunction($abiName), $str);
        $failed = $context->builder->icmp(Builder::INT_SLT, $bytes, $i64->constInt(0, true));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'disk_space_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'disk_space_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'disk_space_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $warnFn = StatPathRuntime::FN_DISK_FREE === $abiName ? 'disk_free_space' : 'disk_total_space';
        self::emitStatWarning($context, $warnFn.'(): No such file or directory');
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $bytes);
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

    private static function emitPathStatFailureWarning(
        Context $context,
        Value $pathStr,
        string $function,
        bool $lstat
    ): void {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $op = $lstat ? 'Lstat' : 'stat';
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $bufSize = $sizeT->constInt(512, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast($context->constantFromString('%s(): %s failed for %s'), $charPtr);
        $fnPtr = $context->builder->pointerCast($context->constantFromString($function), $charPtr);
        $opPtr = $context->builder->pointerCast($context->constantFromString($op), $charPtr);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $fnPtr,
            $opPtr,
            $pathPtr
        );
        $msgPtr = $context->builder->pointerCast($bufChar, $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $context->builder->zExt($written, $sizeT),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
    }
}
