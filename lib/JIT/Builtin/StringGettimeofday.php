<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_gettimeofday_array / __compiler_gettimeofday_float.
 *
 * Mirrors ext/standard/VmDate::gettimeofdayArray()/gettimeofdayFloat() (issue #6110, #3208).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gettimeofday)
 */
final class StringGettimeofday
{
    private const TIMEVAL_SIZE = 16;

    private const TIMEVAL_OFF_TV_SEC = 0;

    private const TIMEVAL_OFF_TV_USEC = 8;

    private const TIMEZONE_SIZE = 8;

    private const TIMEZONE_OFF_MINUTESWEST = 0;

    private const TIMEZONE_OFF_DSTTIME = 4;

    private const USEC_PER_SEC = 1_000_000;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /**
     * Wall-clock parts shared by uniqid() lowering (tv_sec, tv_usec % $usecMod).
     *
     * @return array{0: Value, 1: Value} i32 sec and masked usec
     */
    public static function readSecUsec(Context $context, int $usecMod = 0): array
    {
        self::ensureLinked($context);
        [$sec, $usec] = \array_slice(self::readWallClock($context), 0, 2);

        if ($usecMod <= 0) {
            return [$sec, $usec];
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $mask = $i64->constInt($usecMod - 1, false);
        $usecMasked = $context->builder->truncOrBitCast(
            $context->builder->and($context->builder->zExt($usec, $i64), $mask),
            $i32
        );

        return [$sec, $usecMasked];
    }

    public static function implement(Context $context): void
    {
        $arrayProbe = $context->module->getNamedFunction('__compiler_gettimeofday_array');
        if (null !== $arrayProbe && $arrayProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibcGettimeofday($context);
        self::ensureHashtableHelpers($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $double = $context->getTypeFromString('double');

        $ftArray = $context->context->functionType($htPtr, false);
        $fnArray = null !== $arrayProbe
            ? $arrayProbe
            : $context->module->addFunction('__compiler_gettimeofday_array', $ftArray);
        self::implementGettimeofdayArray($context, $fnArray);

        $floatProbe = $context->module->getNamedFunction('__compiler_gettimeofday_float');
        $ftFloat = $context->context->functionType($double, false);
        $fnFloat = null !== $floatProbe
            ? $floatProbe
            : $context->module->addFunction('__compiler_gettimeofday_float', $ftFloat);
        self::implementGettimeofdayFloat($context, $fnFloat);

        self::registerLinkedRuntime($context);
    }

    private static function implementGettimeofdayFloat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gtv_float_entry');
        $context->builder->positionAtEnd($entry);

        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        [$sec, $usec, $ok] = self::readWallClock($context);

        $failBb = $fn->appendBasicBlock('gtv_float_fail');
        $calcBb = $fn->appendBasicBlock('gtv_float_calc');
        $context->builder->branchIf($ok, $calcBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($calcBb);
        $context->builder->returnValue(self::wallClockToDouble($context, $sec, $usec));
        $context->builder->clearInsertionPosition();
    }

    private static function implementGettimeofdayArray(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gtv_array_entry');
        $context->builder->positionAtEnd($entry);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $zero64 = $i64->constInt(0, false);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $nullHt = $htPtr->constNull();
        $allocFailBb = $fn->appendBasicBlock('gtv_array_alloc_fail');
        $clockBb = $fn->appendBasicBlock('gtv_array_clock');
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $context->builder->branchIf($htNull, $allocFailBb, $clockBb);

        $context->builder->positionAtEnd($allocFailBb);
        $context->builder->returnValue($nullHt);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($clockBb);
        $tv = $context->builder->alloca($i8, self::TIMEVAL_SIZE, 'gtv_tv');
        $tz = $context->builder->alloca($i8, self::TIMEZONE_SIZE, 'gtv_tz');
        $tvPtr = $context->builder->pointerCast($tv, $i8p);
        $tzPtr = $context->builder->pointerCast($tz, $i8p);
        $status = $context->builder->call(
            $context->lookupFunction('gettimeofday'),
            $tvPtr,
            $tzPtr
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $status, $zeroI32);

        $secRaw = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_SEC);
        $usecRaw = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_USEC);
        $minutesRaw = self::loadI32At($context, $tz, self::TIMEZONE_OFF_MINUTESWEST);
        $dstRaw = self::loadI32At($context, $tz, self::TIMEZONE_OFF_DSTTIME);

        $sec = $context->builder->select($ok, $secRaw, $zero64);
        $usec = $context->builder->select($ok, $usecRaw, $zero64);
        $minutes = $context->builder->select(
            $ok,
            $context->builder->zExt($minutesRaw, $i64),
            $zero64
        );
        $dst = $context->builder->select(
            $ok,
            $context->builder->zExt($dstRaw, $i64),
            $zero64
        );

        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        foreach ([
            'sec' => $sec,
            'usec' => $usec,
            'minuteswest' => $minutes,
            'dsttime' => $dst,
        ] as $key => $val) {
            $context->builder->call(
                $setLong,
                $ht,
                self::literalString($context, $key),
                $val
            );
        }

        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @return array{0: Value, 1: Value, 2: Value} tv_sec, tv_usec (i32), ok (i1)
     */
    private static function readWallClock(Context $context): array
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $zero64 = $i64->constInt(0, false);

        $tv = $context->builder->alloca($i8, self::TIMEVAL_SIZE, 'gtv_tv');
        $tvPtr = $context->builder->pointerCast($tv, $i8p);
        $status = $context->builder->call(
            $context->lookupFunction('gettimeofday'),
            $tvPtr,
            $i8p->constNull()
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $status, $zeroI32);
        $secRaw = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_SEC);
        $usecRaw = self::loadI64At($context, $tv, self::TIMEVAL_OFF_TV_USEC);
        $sec = $context->builder->truncOrBitCast(
            $context->builder->select($ok, $secRaw, $zero64),
            $i32
        );
        $usec = $context->builder->truncOrBitCast(
            $context->builder->select($ok, $usecRaw, $zero64),
            $i32
        );

        return [$sec, $usec, $ok];
    }

    private static function wallClockToDouble(Context $context, Value $sec, Value $usec): Value
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $usecPerSec = $i64->constInt(self::USEC_PER_SEC, false);
        $secD = $context->builder->sitofp($context->builder->zExt($sec, $i64), $double);
        $usecD = $context->builder->sitofp($context->builder->zExt($usec, $i64), $double);
        $divisor = $context->builder->sitofp($usecPerSec, $double);

        return $context->builder->fAdd($secD, $context->builder->fDiv($usecD, $divisor));
    }

    private static function loadI64At(Context $context, Value $base, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));
        $slot = $context->builder->pointerCast($ptr, $i64->pointerType(0));

        return $context->builder->load($slot);
    }

    private static function loadI32At(Context $context, Value $base, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));
        $slot = $context->builder->pointerCast($ptr, $i32->pointerType(0));

        return $context->builder->load($slot);
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

    private static function ensureLibcGettimeofday(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            'gettimeofday',
            $context->context->functionType($i32, false, $i8p, $i8p)
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__string__init', $strPtr, [$i64, $charPtr]],
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
        foreach (['__compiler_gettimeofday_array', '__compiler_gettimeofday_float'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringGettimeofday LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
