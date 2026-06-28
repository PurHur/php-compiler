<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
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
            $context->constantFromString('%s(%s): Failed to open directory: No such file or directory'),
            $charPtr
        );
        $fnPtr = $context->builder->pointerCast($context->constantFromString($function), $charPtr);
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
