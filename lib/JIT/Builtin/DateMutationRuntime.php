<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM scalar helpers for date_add/date_sub/date_modify/date_diff (#4604 phase 2).
 *
 * Mirrors ext/standard/VmDateTimeNative::{applyIntervalState,modifyRelative,diffTimestamps}.
 * php-src: ext/date/php_date.c — php_date_add, php_date_sub, php_date_diff, date_modify
 */
final class DateMutationRuntime
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
        $restore = self::captureInsertBlock($context);
        self::implementApplyInterval($context);
        self::implementModifyDelta($context);
        self::implementDiffScalars($context);
        self::restoreInsertBlock($context, $restore);
    }

    private static function implementApplyInterval(Context $context): void
    {
        $name = '__phpc_date_apply_interval';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        self::ensureTmExternals($context);

        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');

        $ft = $context->context->functionType(
            $voidTy,
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $dbl,
            $i64,
            $i1,
            $i8p,
            $i64p,
            $i64p
        );
        $fn = null !== $probe ? $probe : $context->module->addFunction($name, $ft);
        self::emitApplyInterval($context, $fn);
        $context->registerFunction($name, $fn);
    }

    private static function emitApplyInterval(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('date_ai_entry');
        $context->builder->positionAtEnd($entry);

        $ts = $fn->getParam(0);
        $micro = $fn->getParam(1);
        $iy = $fn->getParam(2);
        $im = $fn->getParam(3);
        $id = $fn->getParam(4);
        $ih = $fn->getParam(5);
        $ii = $fn->getParam(6);
        $is = $fn->getParam(7);
        $if = $fn->getParam(8);
        $invert = $fn->getParam(9);
        $add = $fn->getParam(10);
        $tzCstr = $fn->getParam(11);
        $outTs = $fn->getParam(12);
        $outMicro = $fn->getParam(13);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);
        $negOne = $i64->constInt(-1, true);
        $yearBase = $i32->constInt(1900, false);
        $million = $i64->constInt(1_000_000, false);
        $dbl = $context->getTypeFromString('double');
        $millionD = $dbl->constReal(1_000_000.0);

        self::setenvTz($context, $tzCstr);

        $invertNonZero = $context->builder->icmp(Builder::INT_NE, $invert, $zero);
        $subtractWhenAdd = $invertNonZero;
        $subtractBb = $fn->appendBasicBlock('date_ai_sub');
        $addBb = $fn->appendBasicBlock('date_ai_add');
        $signBb = $fn->appendBasicBlock('date_ai_sign');
        $context->builder->branchIf($add, $subtractBb, $addBb);
        $context->builder->positionAtEnd($subtractBb);
        $subWhenAddPhi = $subtractWhenAdd;
        $context->builder->branch($signBb);
        $context->builder->positionAtEnd($addBb);
        $subWhenNotAdd = $context->builder->xor($invertNonZero, $context->getTypeFromString('int1')->constInt(1, false));
        $context->builder->branch($signBb);
        $context->builder->positionAtEnd($signBb);
        $subtract = $context->builder->phi($context->getTypeFromString('int1'), 'date_ai_subtract');
        $subtract->addIncoming($subWhenAddPhi, $subtractBb);
        $subtract->addIncoming($subWhenNotAdd, $addBb);
        $sign = $context->builder->select($subtract, $negOne, $one);

        $tsSlot = $context->builder->alloca($i64, 1, 'date_ai_ts');
        $context->builder->store($ts, $tsSlot);
        $tmBuf = $context->builder->alloca($i32, 9, 'date_ai_tm');
        $tmPtr = $context->builder->pointerCast($tmBuf, $i8p);
        $tmResult = $context->builder->call(
            $context->lookupFunction('localtime_r'),
            $context->builder->pointerCast($tsSlot, $i64p),
            $tmPtr
        );
        $tmFailed = $context->builder->icmp(Builder::INT_EQ, $tmResult, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('date_ai_fail');
        $bodyBb = $fn->appendBasicBlock('date_ai_body');
        $context->builder->branchIf($tmFailed, $failBb, $bodyBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->store($ts, $outTs);
        $context->builder->store($micro, $outMicro);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $year = $context->builder->add(
            $context->builder->add(
                $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_YEAR), $i64),
                $context->builder->zExt($yearBase, $i64)
            ),
            $context->builder->mul($sign, $iy)
        );
        $month = $context->builder->add(
            $context->builder->add(
                $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MON), $i64),
                $one
            ),
            $context->builder->mul($sign, $im)
        );
        $day = $context->builder->add(
            $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MDAY), $i64),
            $context->builder->mul($sign, $id)
        );
        $hour = $context->builder->add(
            $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_HOUR), $i64),
            $context->builder->mul($sign, $ih)
        );
        $minute = $context->builder->add(
            $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MIN), $i64),
            $context->builder->mul($sign, $ii)
        );
        $second = $context->builder->add(
            $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_SEC), $i64),
            $context->builder->mul($sign, $is)
        );

        self::storeTmField($context, $tmBuf, self::TM_SEC, $context->builder->trunc($second, $i32));
        self::storeTmField($context, $tmBuf, self::TM_MIN, $context->builder->trunc($minute, $i32));
        self::storeTmField($context, $tmBuf, self::TM_HOUR, $context->builder->trunc($hour, $i32));
        self::storeTmField($context, $tmBuf, self::TM_MDAY, $context->builder->trunc($day, $i32));
        $monZero = $context->builder->subNoSignedWrap($month, $one);
        self::storeTmField($context, $tmBuf, self::TM_MON, $context->builder->trunc($monZero, $i32));
        $yearSince1900 = $context->builder->sub($year, $context->builder->zExt($yearBase, $i64));
        self::storeTmField($context, $tmBuf, self::TM_YEAR, $context->builder->trunc($yearSince1900, $i32));
        self::storeTmField($context, $tmBuf, self::TM_ISDST, $i32->constInt(-1, true));

        $newTs = $context->builder->call($context->lookupFunction('mktime'), $tmPtr);
        $fracMicro = $context->builder->fptosi(
            $context->builder->fmul($if, $millionD),
            $i64
        );
        $newMicro = $context->builder->add($micro, $context->builder->mul($sign, $fracMicro));

        $microGe = $context->builder->icmp(Builder::INT_SGE, $newMicro, $million);
        $microLt = $context->builder->icmp(Builder::INT_SLT, $newMicro, $zero);
        $carryBb = $fn->appendBasicBlock('date_ai_carry');
        $borrowBb = $fn->appendBasicBlock('date_ai_borrow');
        $storeBb = $fn->appendBasicBlock('date_ai_store');
        $afterCarryBb = $fn->appendBasicBlock('date_ai_after_carry');
        $context->builder->branchIf($microGe, $carryBb, $afterCarryBb);

        $context->builder->positionAtEnd($carryBb);
        $carryTs = $context->builder->add($newTs, $context->builder->signedDiv($newMicro, $million));
        $carryMicro = $context->builder->signedRem($newMicro, $million);
        $context->builder->branch($storeBb);

        $context->builder->positionAtEnd($afterCarryBb);
        $context->builder->branchIf($microLt, $borrowBb, $storeBb);

        $context->builder->positionAtEnd($borrowBb);
        $borrowTs = $context->builder->sub($newTs, $one);
        $borrowMicro = $context->builder->add($newMicro, $million);
        $context->builder->branch($storeBb);

        $context->builder->positionAtEnd($storeBb);
        $finalTs = $context->builder->phi($i64, 'date_ai_final_ts');
        $finalMicro = $context->builder->phi($i64, 'date_ai_final_micro');
        $finalTs->addIncoming($newTs, $afterCarryBb);
        $finalMicro->addIncoming($newMicro, $afterCarryBb);
        $finalTs->addIncoming($carryTs, $carryBb);
        $finalMicro->addIncoming($carryMicro, $carryBb);
        $finalTs->addIncoming($borrowTs, $borrowBb);
        $finalMicro->addIncoming($borrowMicro, $borrowBb);

        $context->builder->store($finalTs, $outTs);
        $context->builder->store($finalMicro, $outMicro);
        $context->builder->returnVoid();
    }

    /**
     * Apply a compile-time parsed relative delta (amount + unit code) to a timestamp.
     *
     * unit: 0=second, 1=minute, 2=hour, 3=day, 4=week, 5=month, 6=year
     */
    private static function implementModifyDelta(Context $context): void
    {
        $name = '__phpc_date_modify_delta';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        self::ensureTmExternals($context);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');

        $ft = $context->context->functionType($voidTy, false, $i64, $i64, $i64, $i8p, $i64p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($name, $ft);
        self::emitModifyDelta($context, $fn);
        $context->registerFunction($name, $fn);
    }

    private static function emitModifyDelta(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('date_md_entry');
        $context->builder->positionAtEnd($entry);

        $ts = $fn->getParam(0);
        $amount = $fn->getParam(1);
        $unit = $fn->getParam(2);
        $tzCstr = $fn->getParam(3);
        $outTs = $fn->getParam(4);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $one = $i64->constInt(1, false);
        $seven = $i64->constInt(7, false);
        $yearBase = $i32->constInt(1900, false);

        self::setenvTz($context, $tzCstr);

        $tsSlot = $context->builder->alloca($i64, 1, 'date_md_ts');
        $context->builder->store($ts, $tsSlot);
        $tmBuf = $context->builder->alloca($i32, 9, 'date_md_tm');
        $tmPtr = $context->builder->pointerCast($tmBuf, $i8p);
        $tmResult = $context->builder->call(
            $context->lookupFunction('localtime_r'),
            $context->builder->pointerCast($tsSlot, $i64p),
            $tmPtr
        );
        $tmFailed = $context->builder->icmp(Builder::INT_EQ, $tmResult, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('date_md_fail');
        $bodyBb = $fn->appendBasicBlock('date_md_body');
        $context->builder->branchIf($tmFailed, $failBb, $bodyBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->store($ts, $outTs);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $second = $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_SEC), $i64);
        $minute = $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MIN), $i64);
        $hour = $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_HOUR), $i64);
        $day = $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MDAY), $i64);
        $month = $context->builder->add(
            $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MON), $i64),
            $one
        );
        $year = $context->builder->add(
            $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_YEAR), $i64),
            $context->builder->zExt($yearBase, $i64)
        );

        $weekAmount = $context->builder->mul($amount, $seven);
        $unitSecond = $context->builder->icmp(Builder::INT_EQ, $unit, $i64->constInt(0, false));
        $unitMinute = $context->builder->icmp(Builder::INT_EQ, $unit, $one);
        $unitHour = $context->builder->icmp(Builder::INT_EQ, $unit, $i64->constInt(2, false));
        $unitDay = $context->builder->icmp(Builder::INT_EQ, $unit, $i64->constInt(3, false));
        $unitWeek = $context->builder->icmp(Builder::INT_EQ, $unit, $i64->constInt(4, false));
        $unitMonth = $context->builder->icmp(Builder::INT_EQ, $unit, $i64->constInt(5, false));

        $second = $context->builder->select($unitSecond, $context->builder->add($second, $amount), $second);
        $minute = $context->builder->select($unitMinute, $context->builder->add($minute, $amount), $minute);
        $hour = $context->builder->select($unitHour, $context->builder->add($hour, $amount), $hour);
        $day = $context->builder->select($unitDay, $context->builder->add($day, $amount), $day);
        $day = $context->builder->select($unitWeek, $context->builder->add($day, $weekAmount), $day);
        $month = $context->builder->select($unitMonth, $context->builder->add($month, $amount), $month);
        $year = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $unit, $i64->constInt(6, false)),
            $context->builder->add($year, $amount),
            $year
        );

        self::storeTmField($context, $tmBuf, self::TM_SEC, $context->builder->trunc($second, $i32));
        self::storeTmField($context, $tmBuf, self::TM_MIN, $context->builder->trunc($minute, $i32));
        self::storeTmField($context, $tmBuf, self::TM_HOUR, $context->builder->trunc($hour, $i32));
        self::storeTmField($context, $tmBuf, self::TM_MDAY, $context->builder->trunc($day, $i32));
        $monZero = $context->builder->subNoSignedWrap($month, $one);
        self::storeTmField($context, $tmBuf, self::TM_MON, $context->builder->trunc($monZero, $i32));
        $yearSince1900 = $context->builder->sub($year, $context->builder->zExt($yearBase, $i64));
        self::storeTmField($context, $tmBuf, self::TM_YEAR, $context->builder->trunc($yearSince1900, $i32));
        self::storeTmField($context, $tmBuf, self::TM_ISDST, $i32->constInt(-1, true));

        $newTs = $context->builder->call($context->lookupFunction('mktime'), $tmPtr);
        $context->builder->store($newTs, $outTs);
        $context->builder->returnVoid();
    }

    private static function implementDiffScalars(Context $context): void
    {
        $name = '__phpc_date_diff_scalars';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        self::ensureTmExternals($context);

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');

        $ft = $context->context->functionType(
            $voidTy,
            false,
            $i64,
            $i64,
            $i1,
            $i8p,
            $i64p,
            $i64p,
            $i64p,
            $i64p,
            $i64p,
            $i64p,
            $i64p,
            $i64p
        );
        $fn = null !== $probe ? $probe : $context->module->addFunction($name, $ft);
        self::emitDiffScalars($context, $fn);
        $context->registerFunction($name, $fn);
    }

    private static function emitDiffScalars(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('date_df_entry');
        $context->builder->positionAtEnd($entry);

        $baseTs = $fn->getParam(0);
        $targetTs = $fn->getParam(1);
        $absolute = $fn->getParam(2);
        $tzCstr = $fn->getParam(3);
        $outY = $fn->getParam(4);
        $outM = $fn->getParam(5);
        $outD = $fn->getParam(6);
        $outH = $fn->getParam(7);
        $outI = $fn->getParam(8);
        $outS = $fn->getParam(9);
        $outInvert = $fn->getParam(10);
        $outDays = $fn->getParam(11);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $yearBase = $i32->constInt(1900, false);
        $daySec = $i64->constInt(86_400, false);
        $sixty = $i64->constInt(60, false);
        $twentyFour = $i64->constInt(24, false);
        $twelve = $i64->constInt(12, false);

        self::setenvTz($context, $tzCstr);

        $invert = $context->builder->icmp(Builder::INT_SLT, $targetTs, $baseTs);
        $earlier = $context->builder->select($invert, $targetTs, $baseTs);
        $later = $context->builder->select($invert, $baseTs, $targetTs);
        $diffSec = $context->builder->sub($later, $earlier);
        $days = $context->builder->signedDiv($diffSec, $daySec);
        $invertOut = $context->builder->zExt(
            $context->builder->select($absolute, $context->getTypeFromString('int1')->constInt(0, false), $invert),
            $i64
        );

        $loadTm = static function (Context $context, LlvmFunction $fn, Value $ts, string $tag) use ($i64, $i32, $i8p, $i64p, $one): array {
            $slot = $context->builder->alloca($i64, 1, $tag.'_ts');
            $context->builder->store($ts, $slot);
            $tmBuf = $context->builder->alloca($i32, 9, $tag.'_tm');
            $tmPtr = $context->builder->pointerCast($tmBuf, $i8p);
            $context->builder->call(
                $context->lookupFunction('localtime_r'),
                $context->builder->pointerCast($slot, $i64p),
                $tmPtr
            );

            return [
                'year' => $context->builder->add(
                    $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_YEAR), $i64),
                    $context->builder->zExt($context->getTypeFromString('int32')->constInt(1900, false), $i64)
                ),
                'month' => $context->builder->add(
                    $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MON), $i64),
                    $one
                ),
                'day' => $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MDAY), $i64),
                'hour' => $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_HOUR), $i64),
                'minute' => $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_MIN), $i64),
                'second' => $context->builder->zExt(self::loadTmField($context, $tmPtr, self::TM_SEC), $i64),
            ];
        };

        $e = $loadTm($context, $fn, $earlier, 'date_df_e');
        $l = $loadTm($context, $fn, $later, 'date_df_l');

        $s = $context->builder->sub($l['second'], $e['second']);
        $i = $context->builder->sub($l['minute'], $e['minute']);
        $h = $context->builder->sub($l['hour'], $e['hour']);
        $d = $context->builder->sub($l['day'], $e['day']);
        $m = $context->builder->sub($l['month'], $e['month']);
        $y = $context->builder->sub($l['year'], $e['year']);

        $sLt = $context->builder->icmp(Builder::INT_SLT, $s, $zero);
        $s = $context->builder->select($sLt, $context->builder->add($s, $sixty), $s);
        $i = $context->builder->select($sLt, $context->builder->sub($i, $one), $i);

        $iLt = $context->builder->icmp(Builder::INT_SLT, $i, $zero);
        $i = $context->builder->select($iLt, $context->builder->add($i, $sixty), $i);
        $h = $context->builder->select($iLt, $context->builder->sub($h, $one), $h);

        $hLt = $context->builder->icmp(Builder::INT_SLT, $h, $zero);
        $h = $context->builder->select($hLt, $context->builder->add($h, $twentyFour), $h);
        $d = $context->builder->select($hLt, $context->builder->sub($d, $one), $d);

        $dLt = $context->builder->icmp(Builder::INT_SLT, $d, $zero);
        $prevMonth = $context->builder->sub($l['month'], $one);
        $prevYear = $l['year'];
        $prevMonthLt = $context->builder->icmp(Builder::INT_SLT, $prevMonth, $one);
        $prevMonth = $context->builder->select($prevMonthLt, $twelve, $prevMonth);
        $prevYear = $context->builder->select($prevMonthLt, $context->builder->sub($prevYear, $one), $prevYear);
        $dim = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $prevMonth, $i64->constInt(2, false)),
            $i64->constInt(28, false),
            $i64->constInt(30, false)
        );
        $d = $context->builder->select($dLt, $context->builder->add($d, $dim), $d);
        $m = $context->builder->select($dLt, $context->builder->sub($m, $one), $m);

        $mLt = $context->builder->icmp(Builder::INT_SLT, $m, $zero);
        $m = $context->builder->select($mLt, $context->builder->add($m, $twelve), $m);
        $y = $context->builder->select($mLt, $context->builder->sub($y, $one), $y);

        $context->builder->store($y, $outY);
        $context->builder->store($m, $outM);
        $context->builder->store($d, $outD);
        $context->builder->store($h, $outH);
        $context->builder->store($i, $outI);
        $context->builder->store($s, $outS);
        $context->builder->store($invertOut, $outInvert);
        $context->builder->store($days, $outDays);
        $context->builder->returnVoid();
    }

    private static function setenvTz(Context $context, Value $tzCstr): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $context->lookupFunction('setenv'),
            $context->builder->pointerCast($context->constantFromString('TZ'), $i8p),
            $tzCstr,
            $i32->constInt(1, false)
        );
    }

    private static function loadTmField(Context $context, Value $tmPtr, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->load(
            $context->builder->pointerCast(
                $context->builder->gep($tmPtr, $i32->constInt($offset, false)),
                $i32->pointerType(0)
            )
        );
    }

    private static function storeTmField(Context $context, Value $tmBuf, int $offset, Value $value): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $ptr = $context->builder->pointerCast(
            $context->builder->gep($context->builder->pointerCast($tmBuf, $i8p), $i32->constInt($offset, false)),
            $i32->pointerType(0)
        );
        $context->builder->store($value, $ptr);
    }

    private static function ensureTmExternals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i64p = $context->getTypeFromString('int64*');

        foreach ([
            ['setenv', $i32, [$i8p, $i8p, $i32]],
            ['localtime_r', $i8p, [$i64p, $i8p]],
            ['mktime', $i64, [$i8p]],
        ] as [$name, $ret, $params]) {
            $existing = $context->module->getNamedFunction($name);
            $fn = null !== $existing
                ? $existing
                : $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
            try {
                $context->lookupFunction($name);
            } catch (\LogicException) {
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
