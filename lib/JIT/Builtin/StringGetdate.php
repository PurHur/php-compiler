<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_getdate — localtime + hashtable breakdown.
 *
 * Mirrors ext/standard/VmDate::getdate() (issue #5256, #3510).
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(getdate)
 */
final class StringGetdate
{
    private const TM_SEC = 0;

    private const TM_MIN = 4;

    private const TM_HOUR = 8;

    private const TM_MDAY = 12;

    private const TM_MON = 16;

    private const TM_YEAR = 20;

    private const TM_WDAY = 24;

    private const TM_YDAY = 28;

    /** @var list<string> */
    private const WEEKDAYS = [
        'Sunday',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
    ];

    /** @var list<string> */
    private const MONTHS = [
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_getdate');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureHashtableHelpers($context);

        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType($voidTy, false, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_getdate', $ft);
        self::implementGetdate($context, $fn);

        self::registerLinkedRuntime($context);
    }

    private static function implementGetdate(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gd_entry');
        $context->builder->positionAtEnd($entry);

        $timestamp = $fn->getParam(0);
        $out = $fn->getParam(1);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $i64->constInt(1, false);
        $yearBase = $i32->constInt(1900, false);
        $idx0 = $sizeT->constInt(0, false);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullRetBb = $fn->appendBasicBlock('gd_null_out');
        $localBb = $fn->appendBasicBlock('gd_localtime');
        $context->builder->branchIf($nullOut, $nullRetBb, $localBb);

        $context->builder->positionAtEnd($nullRetBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($localBb);
        $i64p = $context->getTypeFromString('int64*');
        $tsSlot = $context->builder->alloca($i64, 1, 'gd_ts');
        $context->builder->store($timestamp, $tsSlot);
        $tsPtr = $context->builder->pointerCast($tsSlot, $i64p);
        $tmPtr = $context->builder->call($context->lookupFunction('localtime'), $tsPtr);
        $tmNull = $context->builder->icmp(Builder::INT_EQ, $tmPtr, $i8p->constNull());
        $tmFailBb = $fn->appendBasicBlock('gd_tm_fail');
        $fillBb = $fn->appendBasicBlock('gd_fill');
        $context->builder->branchIf($tmNull, $tmFailBb, $fillBb);

        $context->builder->positionAtEnd($tmFailBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($fillBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $allocFailBb = $fn->appendBasicBlock('gd_alloc_fail');
        $keysBb = $fn->appendBasicBlock('gd_keys');
        $context->builder->branchIf($htNull, $allocFailBb, $keysBb);

        $context->builder->positionAtEnd($allocFailBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($keysBb);
        $tmSec = self::loadTmField($context, $tmPtr, self::TM_SEC);
        $tmMin = self::loadTmField($context, $tmPtr, self::TM_MIN);
        $tmHour = self::loadTmField($context, $tmPtr, self::TM_HOUR);
        $tmMday = self::loadTmField($context, $tmPtr, self::TM_MDAY);
        $tmMon = self::loadTmField($context, $tmPtr, self::TM_MON);
        $tmYear = self::loadTmField($context, $tmPtr, self::TM_YEAR);
        $tmWday = self::loadTmField($context, $tmPtr, self::TM_WDAY);
        $tmYday = self::loadTmField($context, $tmPtr, self::TM_YDAY);

        $year = $context->builder->add(
            $context->builder->zExt($tmYear, $i64),
            $context->builder->zExt($yearBase, $i64)
        );
        $mon = $context->builder->addNoSignedWrap($context->builder->zExt($tmMon, $i64), $one);
        $sec = $context->builder->zExt($tmSec, $i64);
        $min = $context->builder->zExt($tmMin, $i64);
        $hour = $context->builder->zExt($tmHour, $i64);
        $mday = $context->builder->zExt($tmMday, $i64);
        $wday = $context->builder->zExt($tmWday, $i64);
        $yday = $context->builder->zExt($tmYday, $i64);

        $weekdayStr = self::selectName($context, $tmWday, self::WEEKDAYS);
        $monthStr = self::selectName($context, $tmMon, self::MONTHS);

        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        $setAt = $context->lookupFunction('__hashtable__setLongAt');

        foreach ([
            'seconds' => $sec,
            'minutes' => $min,
            'hours' => $hour,
            'mday' => $mday,
            'wday' => $wday,
            'mon' => $mon,
            'year' => $year,
            'yday' => $yday,
        ] as $key => $val) {
            $context->builder->call(
                $setLong,
                $ht,
                self::literalString($context, $key),
                $val
            );
        }
        $context->builder->call(
            $setString,
            $ht,
            self::literalString($context, 'weekday'),
            $weekdayStr
        );
        $context->builder->call(
            $setString,
            $ht,
            self::literalString($context, 'month'),
            $monthStr
        );
        $context->builder->call($setAt, $ht, $idx0, $timestamp);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function loadTmField(Context $context, Value $tmPtr, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i32p = $context->getTypeFromString('int32*');
        $tmFields = $context->builder->pointerCast($tmPtr, $i32p);

        return $context->builder->load(
            $context->builder->gep($tmFields, $i32->constInt((int) ($offset / 4), false))
        );
    }

    /**
     * @param list<string> $names
     */
    private static function selectName(Context $context, Value $indexI32, array $names): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $result = self::literalString($context, $names[0]);
        for ($i = \count($names) - 1; $i >= 1; --$i) {
            $eq = $context->builder->icmp(Builder::INT_EQ, $indexI32, $i32->constInt($i, false));
            $candidate = self::literalString($context, $names[$i]);
            $result = $context->builder->select($eq, $candidate, $result);
        }

        return $result;
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__hashtable__setLongAt', $voidTy, [$htPtr, $sizeT, $i64]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
            ['__string__init', $strPtr, [$i64, $context->getTypeFromString('int8*')]],
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
        $fn = $context->module->getNamedFunction('__compiler_getdate');
        if (null === $fn) {
            throw new \LogicException('__compiler_getdate missing after StringGetdate LLVM implement');
        }
        $context->registerFunction('__compiler_getdate', $fn);
    }
}
