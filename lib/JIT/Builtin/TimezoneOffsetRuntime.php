<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM timezone_offset_get() offset math — setenv TZ + localtime + timegm (#6041 phase 2).
 *
 * Mirrors ext/standard/VmDateTimeNative::timezoneOffsetSeconds().
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_offset_get)
 */
final class TimezoneOffsetRuntime
{
    private const TM_SEC = 0;

    private const TM_MIN = 4;

    private const TM_HOUR = 8;

    private const TM_MDAY = 12;

    private const TM_MON = 16;

    private const TM_YEAR = 20;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_timezone_offset_seconds');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternals($context);

        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType($voidTy, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__phpc_timezone_offset_seconds', $ft);
        self::implementOffset($context, $fn);

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementOffset(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('tzoff_entry');
        $context->builder->positionAtEnd($entry);

        $tzStr = $fn->getParam(0);
        $timestamp = $fn->getParam(1);
        $out = $fn->getParam(2);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroI32 = $i32->constInt(0, false);

        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullTz = $context->builder->icmp(Builder::INT_EQ, $tzStr, $strPtr->constNull());
        $nullBb = $fn->appendBasicBlock('tzoff_null');
        $bodyBb = $fn->appendBasicBlock('tzoff_body');
        $context->builder->branchIf(
            $context->builder->or($nullOut, $nullTz),
            $nullBb,
            $bodyBb
        );

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $tzCStr = self::stringData($context, $tzStr);
        $context->builder->call(
            $context->lookupFunction('setenv'),
            $context->builder->pointerCast($context->constantFromString('TZ'), $i8p),
            $tzCStr,
            $i32->constInt(1, false)
        );

        $tsSlot = $context->builder->alloca($i64, $i64->constInt(1, false), 'ts_slot');
        $context->builder->store($timestamp, $tsSlot);
        $tsPtr = $context->builder->pointerCast($tsSlot, $i64p);

        $tmSize = $i64->constInt(56, false);
        $tmBuf = $context->builder->alloca($i8, $tmSize, 'tm_buf');
        $tmPtr = $context->builder->pointerCast($tmBuf, $i8p);
        $tmResult = $context->builder->call(
            $context->lookupFunction('localtime_r'),
            $tsPtr,
            $tmPtr
        );
        $localFailed = $context->builder->icmp(Builder::INT_EQ, $tmResult, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('tzoff_fail');
        $okBb = $fn->appendBasicBlock('tzoff_ok');
        $context->builder->branchIf($localFailed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $zeroI32
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBb);
        $utc = $context->builder->call($context->lookupFunction('timegm'), $tmPtr);
        $offset = $context->builder->sub($utc, $timestamp);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $offset
        );
        $context->builder->returnVoid();
    }

    private static function stringData(Context $context, Value $strPtr): Value
    {
        $off = $context->structFieldIndex($strPtr, 'value');

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $off),
            $context->getTypeFromString('int8*')
        );
    }

    private static function ensureExternals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        foreach ([
            ['setenv', $i32, [$i8p, $i8p, $i32]],
            ['localtime_r', $i8p, [$i64p, $i8p]],
            ['timegm', $i64, [$i8p]],
            ['__value__writeLong', $voidTy, [$valuePtr, $i64]],
        ] as [$name, $ret, $params]) {
            if (null === $context->module->getNamedFunction($name)) {
                $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $context->registerFunction(
            '__phpc_timezone_offset_seconds',
            $context->module->getNamedFunction('__phpc_timezone_offset_seconds')
        );
    }
}
