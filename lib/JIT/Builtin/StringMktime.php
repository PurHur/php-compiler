<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_mktime — local mktime via libc mktime (#3292).
 *
 * Mirrors ext/standard/VmDate::mktime(). php-src: ext/date/php_date.c PHP_FUNCTION(mktime).
 */
final class StringMktime
{
    private const TM_SEC = 0;

    private const TM_MIN = 4;

    private const TM_HOUR = 8;

    private const TM_MDAY = 12;

    private const TM_MON = 16;

    private const TM_YEAR = 20;

    private const TM_ISDST = 32;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_mktime');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureHelpers($context);

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType(
            $voidTy,
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i1,
            $valuePtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_mktime', $ft);
        self::implementMktime($context, $fn);

        self::registerLinkedRuntime($context);
    }

    private static function implementMktime(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('mkt_entry');
        $context->builder->positionAtEnd($entry);

        $hour = $fn->getParam(0);
        $minute = $fn->getParam(1);
        $second = $fn->getParam(2);
        $month = $fn->getParam(3);
        $day = $fn->getParam(4);
        $year = $fn->getParam(5);
        $useCurrentLocal = $fn->getParam(6);
        $out = $fn->getParam(7);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64p = $context->getTypeFromString('int64*');
        $one = $i64->constInt(1, false);
        $yearBase = $i32->constInt(1900, false);
        $minusOne = $i64->constInt(-1, true);
        $dstUnknown = $i32->constInt(-1, true);

        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullRetBb = $fn->appendBasicBlock('mkt_null_out');
        $resolveBb = $fn->appendBasicBlock('mkt_resolve');
        $context->builder->branchIf($nullOut, $nullRetBb, $resolveBb);

        $context->builder->positionAtEnd($nullRetBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($resolveBb);
        $currentBb = $fn->appendBasicBlock('mkt_current');
        $passedBb = $fn->appendBasicBlock('mkt_passed');
        $mergeBb = $fn->appendBasicBlock('mkt_merge');
        $context->builder->branchIf($useCurrentLocal, $currentBb, $passedBb);

        $context->builder->positionAtEnd($currentBb);
        $now = $context->builder->call($context->lookupFunction('time'), $i8p->constNull());
        $tsSlot = $context->builder->alloca($i64, 1, 'mkt_now');
        $context->builder->store($now, $tsSlot);
        $tsPtr = $context->builder->pointerCast($tsSlot, $i64p);
        $tmPtr = $context->builder->call($context->lookupFunction('localtime'), $tsPtr);
        $tmNull = $context->builder->icmp(Builder::INT_EQ, $tmPtr, $i8p->constNull());
        $tmFailBb = $fn->appendBasicBlock('mkt_tm_fail');
        $tmOkBb = $fn->appendBasicBlock('mkt_tm_ok');
        $context->builder->branchIf($tmNull, $tmFailBb, $tmOkBb);

        $context->builder->positionAtEnd($tmFailBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $context->getTypeFromString('int8')->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($tmOkBb);
        $curMin = $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MIN), $i64);
        $curSec = $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_SEC), $i64);
        $curMon = $context->builder->addNoSignedWrap(
            $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MON), $i64),
            $one
        );
        $curDay = $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MDAY), $i64);
        $curYear = $context->builder->add(
            $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_YEAR), $i64),
            $context->builder->zExt($yearBase, $i64)
        );
        $context->builder->branch($mergeBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($passedBb);
        $context->builder->branch($mergeBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($mergeBb);
        $phiMin = $context->builder->phi($i64, 'mkt_min');
        $phiSec = $context->builder->phi($i64, 'mkt_sec');
        $phiMon = $context->builder->phi($i64, 'mkt_mon');
        $phiDay = $context->builder->phi($i64, 'mkt_day');
        $phiYear = $context->builder->phi($i64, 'mkt_year');
        $phiMin->addIncoming($curMin, $tmOkBb);
        $phiMin->addIncoming($minute, $passedBb);
        $phiSec->addIncoming($curSec, $tmOkBb);
        $phiSec->addIncoming($second, $passedBb);
        $phiMon->addIncoming($curMon, $tmOkBb);
        $phiMon->addIncoming($month, $passedBb);
        $phiDay->addIncoming($curDay, $tmOkBb);
        $phiDay->addIncoming($day, $passedBb);
        $phiYear->addIncoming($curYear, $tmOkBb);
        $phiYear->addIncoming($year, $passedBb);

        $tmBuf = $context->builder->alloca($i32, 9, 'mkt_tm');
        self::storeTmField($context, $tmBuf, self::TM_SEC, $context->builder->trunc($phiSec, $i32));
        self::storeTmField($context, $tmBuf, self::TM_MIN, $context->builder->trunc($phiMin, $i32));
        self::storeTmField($context, $tmBuf, self::TM_HOUR, $context->builder->trunc($hour, $i32));
        self::storeTmField($context, $tmBuf, self::TM_MDAY, $context->builder->trunc($phiDay, $i32));
        $monZero = $context->builder->subNoSignedWrap($phiMon, $one);
        self::storeTmField($context, $tmBuf, self::TM_MON, $context->builder->trunc($monZero, $i32));
        $yearSince1900 = $context->builder->sub($phiYear, $context->builder->zExt($yearBase, $i64));
        self::storeTmField($context, $tmBuf, self::TM_YEAR, $context->builder->trunc($yearSince1900, $i32));
        self::storeTmField($context, $tmBuf, self::TM_ISDST, $dstUnknown);

        $tmPtrOut = $context->builder->pointerCast($tmBuf, $i8p);
        $result = $context->builder->call($context->lookupFunction('mktime'), $tmPtrOut);
        $failed = $context->builder->icmp(Builder::INT_EQ, $result, $minusOne);
        $failBb = $fn->appendBasicBlock('mkt_fail');
        $okBb = $fn->appendBasicBlock('mkt_ok');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $context->getTypeFromString('int8')->constInt(0, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($okBb);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $result);
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

    private static function storeTmField(Context $context, Value $tmBuf, int $offset, Value $valueI32): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i32p = $context->getTypeFromString('int32*');
        $tmFields = $context->builder->pointerCast($tmBuf, $i32p);
        $context->builder->store(
            $valueI32,
            $context->builder->gep($tmFields, $i32->constInt((int) ($offset / 4), false))
        );
    }

    private static function ensureHelpers(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['time', $i64, [$i8p]],
            ['localtime', $i8p, [$i64p]],
            ['mktime', $i64, [$i8p]],
            ['__value__writeLong', $voidTy, [$valuePtr, $i64]],
            ['__value__writeBool', $voidTy, [$valuePtr, $i8]],
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
        $fn = $context->module->getNamedFunction('__compiler_mktime');
        if (null === $fn) {
            throw new \LogicException('__compiler_mktime missing after StringMktime LLVM implement');
        }
        $context->registerFunction('__compiler_mktime', $fn);
    }
}
