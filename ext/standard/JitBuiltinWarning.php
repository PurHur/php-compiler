<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StatPathRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Shared E_WARNING emission for stdlib JIT/AOT builtins (php-src trigger_error parity). */
final class JitBuiltinWarning
{
    public static function emit(Context $context, string $message): void
    {
        self::emitLevel($context, $message, ErrorReporter::E_WARNING);
    }

    public static function emitDeprecated(Context $context, string $message): void
    {
        self::emitLevel($context, $message, ErrorReporter::E_DEPRECATED);
    }

    public static function emitNotice(Context $context, string $message): void
    {
        self::emitLevel($context, $message, ErrorReporter::E_NOTICE);
    }

    /**
     * getimagesize*(): Error reading from {source}! — php-src php_getimagesize_from_any() (#16408).
     */
    public static function emitImageReadFailed(Context $context, Value $sourceStr, string $function): void
    {
        $map = $context->structFieldMap['__string__'];
        $sourcePtr = $context->builder->structGep($sourceStr, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $bufSize = $sizeT->constInt(512, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%s(): Error reading from %s!'),
            $charPtr
        );
        $fnPtr = $context->builder->pointerCast($context->constantFromString($function), $charPtr);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $fnPtr,
            $sourcePtr
        );
        $msgPtr = $context->builder->pointerCast($bufChar, $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $context->builder->zExt($written, $sizeT),
            $i32->constInt(ErrorReporter::E_NOTICE, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
    }

    /** php-src streams.c — fopen/file read failure before getimagesize probe (#16408). */
    public static function emitStreamOpenFailed(Context $context, Value $pathStr, string $function): void
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $bufSize = $sizeT->constInt(512, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%s(%s): Failed to open stream: No such file or directory'),
            $charPtr
        );
        $fnPtr = $context->builder->pointerCast($context->constantFromString($function), $charPtr);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $fnPtr,
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

    private static function emitLevel(Context $context, string $message, int $level): void
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
            $i32->constInt($level, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    public static function emitPathOpenFailed(Context $context, Value $pathStr, string $function): void
    {
        StatPathRuntime::ensureLinked($context);
        $isFile = JitStat::pathIsFile($context, $pathStr);
        $i1 = $context->getTypeFromString('int1');
        $isFileBool = $context->builder->icmp(
            Builder::INT_NE,
            $isFile,
            $i1->constInt(0, false)
        );

        $fileBlock = BasicBlockHelper::append($context, 'path_open_file');
        $missingBlock = BasicBlockHelper::append($context, 'path_open_missing');
        $doneBlock = BasicBlockHelper::append($context, 'path_open_done');
        $context->builder->branchIf($isFileBool, $fileBlock, $missingBlock);

        $context->builder->positionAtEnd($fileBlock);
        self::emitPathOpenDirFailedMessage($context, $pathStr, $function, 'Not a directory');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($missingBlock);
        self::emitPathOpenDirFailedMessage($context, $pathStr, $function, 'No such file or directory');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    private static function emitPathOpenDirFailedMessage(
        Context $context,
        Value $pathStr,
        string $function,
        string $reason
    ): void {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $bufSize = $sizeT->constInt(512, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%s(%s): Failed to open directory: '.$reason),
            $charPtr
        );
        $fnPtr = $context->builder->pointerCast($context->constantFromString($function), $charPtr);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $fnPtr,
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

    public static function emitPathNoSuchFile(Context $context, Value $pathStr, string $function): void
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $bufSize = $sizeT->constInt(512, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%s(%s): No such file or directory'),
            $charPtr
        );
        $fnPtr = $context->builder->pointerCast($context->constantFromString($function), $charPtr);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $fnPtr,
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
