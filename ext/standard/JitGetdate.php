<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefaultTimezoneCivilRuntime;
use PHPCompiler\JIT\Builtin\StringGetdate;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for getdate() — civil math + HT assemble in IR (#5256, #26900).
 *
 * NestedJIT of PHP civil-math helpers (and helper-runtime unit.o of the same)
 * segfaults on user-script AOT init; int-constant helpers and pure LLVM HT
 * writes are green. Mirror DateTimeFormatJitHelper Howard Hinnant math in IR.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getdate)
 */
final class JitGetdate
{
    private const WEEKDAYS = [
        'Sunday',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
    ];

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

    public static function invoke(Context $context, ?JITVariable $timestamp = null): Value
    {
        // Preserve insert-block discipline if StringGetdate ever re-links helpers (#26900).
        StringGetdate::ensureLinked($context);

        $ts = null === $timestamp
            ? JitDate::time($context)
            : JitDateTimestampArg::lowerNullable(
                $context,
                $timestamp,
                'getdate',
                1,
                'timestamp',
                JitDate::time($context)
            );

        $ts = self::localCivilTimestamp($context, $ts);

        $parts = self::civilParts($context, $ts);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::setLong($context, $ht, 'seconds', $parts['second']);
        self::setLong($context, $ht, 'minutes', $parts['minute']);
        self::setLong($context, $ht, 'hours', $parts['hour']);
        self::setLong($context, $ht, 'mday', $parts['day']);
        self::setLong($context, $ht, 'wday', $parts['wday']);
        self::setLong($context, $ht, 'mon', $parts['month']);
        self::setLong($context, $ht, 'year', $parts['year']);
        self::setLong($context, $ht, 'yday', $parts['yday']);
        self::setWeekdayName($context, $ht, $parts['wday']);
        self::setMonthName($context, $ht, $parts['month']);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $context->getTypeFromString('size_t')->constInt(0, false),
            $ts
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    /**
     * UTC civil breakdown for idate/getdate LLVM paths (#26900).
     *
     * @return array{year:Value,month:Value,day:Value,hour:Value,minute:Value,second:Value,wday:Value,yday:Value}
     */
    public static function civilPartsPublic(Context $context, Value $timestamp): array
    {
        return self::civilParts($context, self::localCivilTimestamp($context, $timestamp));
    }

    private static function localCivilTimestamp(Context $context, Value $timestamp): Value
    {
        DefaultTimezoneCivilRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_default_tz_civil_timestamp'),
            $timestamp
        );
    }

    /**
     * UTC unix timestamp from civil fields (Howard Hinnant days_from_civil).
     *
     * Out-of-range {@see $day} overflows like php-src mktime/gmmktime (Feb 31 → Mar 2/3)
     * via days_from_civil(y, m, 1) + (day - 1). Month may be outside 1..12; it is normalized
     * into {@see $year} first (#27262).
     */
    public static function timestampFromCivilPublic(
        Context $context,
        Value $year,
        Value $month,
        Value $day,
        Value $hour,
        Value $minute,
        Value $second
    ): Value {
        return self::timestampFromCivil($context, $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * UTC unix timestamp from civil fields (Howard Hinnant days_from_civil).
     */
    private static function timestampFromCivil(
        Context $context,
        Value $year,
        Value $month,
        Value $day,
        Value $hour,
        Value $minute,
        Value $second
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $c12 = $i64->constInt(12, false);
        $c1 = $i64->constInt(1, false);

        // absMonth = year*12 + (month-1); normalize into year + month∈[1,12]
        $absMonth = $context->builder->add(
            $context->builder->mul($year, $c12),
            $context->builder->sub($month, $c1)
        );
        $normYear = $context->builder->signedDiv($absMonth, $c12);
        $normRem = $context->builder->signedRem($absMonth, $c12);
        $remNeg = $context->builder->icmp(Builder::INT_SLT, $normRem, $i64->constInt(0, false));
        $remAdj = BasicBlockHelper::append($context, 'gd_civ_rem_adj');
        $remOk = BasicBlockHelper::append($context, 'gd_civ_rem_ok');
        $remMerge = BasicBlockHelper::append($context, 'gd_civ_rem_merge');
        $context->builder->branchIf($remNeg, $remAdj, $remOk);

        $context->builder->positionAtEnd($remAdj);
        $yearAdj = $context->builder->sub($normYear, $c1);
        $remPlus = $context->builder->add($normRem, $c12);
        $context->builder->branch($remMerge);
        $context->builder->positionAtEnd($remOk);
        $context->builder->branch($remMerge);
        $context->builder->positionAtEnd($remMerge);
        $yearPhi = $context->builder->phi($i64);
        $yearPhi->addIncoming($yearAdj, $remAdj);
        $yearPhi->addIncoming($normYear, $remOk);
        $remPhi = $context->builder->phi($i64);
        $remPhi->addIncoming($remPlus, $remAdj);
        $remPhi->addIncoming($normRem, $remOk);
        $monthNorm = $context->builder->add($remPhi, $c1);

        $days = self::daysFromCivil($context, $yearPhi, $monthNorm, $c1);
        $days = $context->builder->add($days, $context->builder->sub($day, $c1));

        return $context->builder->add(
            $context->builder->mul($days, $i64->constInt(86400, false)),
            $context->builder->add(
                $context->builder->mul($hour, $i64->constInt(3600, false)),
                $context->builder->add(
                    $context->builder->mul($minute, $i64->constInt(60, false)),
                    $second
                )
            )
        );
    }

    /**
     * Howard Hinnant days_from_civil — day count since 1970-01-01 for civil y/m/d (m in 1..12).
     */
    private static function daysFromCivil(Context $context, Value $year, Value $month, Value $day): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $mLe2 = $context->builder->icmp(Builder::INT_SLE, $month, $i64->constInt(2, false));
        $yAdjBlock = BasicBlockHelper::append($context, 'gd_dfc_y_adj');
        $yKeepBlock = BasicBlockHelper::append($context, 'gd_dfc_y_keep');
        $yMerge = BasicBlockHelper::append($context, 'gd_dfc_y_merge');
        $context->builder->branchIf($mLe2, $yAdjBlock, $yKeepBlock);

        $context->builder->positionAtEnd($yAdjBlock);
        $yMinus = $context->builder->sub($year, $i64->constInt(1, false));
        $context->builder->branch($yMerge);
        $context->builder->positionAtEnd($yKeepBlock);
        $context->builder->branch($yMerge);
        $context->builder->positionAtEnd($yMerge);
        $y = $context->builder->phi($i64);
        $y->addIncoming($yMinus, $yAdjBlock);
        $y->addIncoming($year, $yKeepBlock);

        $yNeg = $context->builder->icmp(Builder::INT_SLT, $y, $i64->constInt(0, false));
        $eraNeg = BasicBlockHelper::append($context, 'gd_dfc_era_neg');
        $eraPos = BasicBlockHelper::append($context, 'gd_dfc_era_pos');
        $eraMerge = BasicBlockHelper::append($context, 'gd_dfc_era_merge');
        $context->builder->branchIf($yNeg, $eraNeg, $eraPos);

        $context->builder->positionAtEnd($eraNeg);
        $eraFromNeg = $context->builder->signedDiv(
            $context->builder->sub($y, $i64->constInt(399, false)),
            $i64->constInt(400, false)
        );
        $context->builder->branch($eraMerge);
        $context->builder->positionAtEnd($eraPos);
        $eraFromPos = $context->builder->signedDiv($y, $i64->constInt(400, false));
        $context->builder->branch($eraMerge);
        $context->builder->positionAtEnd($eraMerge);
        $era = $context->builder->phi($i64);
        $era->addIncoming($eraFromNeg, $eraNeg);
        $era->addIncoming($eraFromPos, $eraPos);

        $yoe = $context->builder->sub($y, $context->builder->mul($era, $i64->constInt(400, false)));

        $mShiftThen = BasicBlockHelper::append($context, 'gd_dfc_m_then');
        $mShiftElse = BasicBlockHelper::append($context, 'gd_dfc_m_else');
        $mShiftMerge = BasicBlockHelper::append($context, 'gd_dfc_m_merge');
        $mGt2 = $context->builder->icmp(Builder::INT_SGT, $month, $i64->constInt(2, false));
        $context->builder->branchIf($mGt2, $mShiftThen, $mShiftElse);
        $context->builder->positionAtEnd($mShiftThen);
        $mA = $context->builder->sub($month, $i64->constInt(3, false));
        $context->builder->branch($mShiftMerge);
        $context->builder->positionAtEnd($mShiftElse);
        $mB = $context->builder->add($month, $i64->constInt(9, false));
        $context->builder->branch($mShiftMerge);
        $context->builder->positionAtEnd($mShiftMerge);
        $mShift = $context->builder->phi($i64);
        $mShift->addIncoming($mA, $mShiftThen);
        $mShift->addIncoming($mB, $mShiftElse);

        $doy = $context->builder->add(
            $context->builder->signedDiv(
                $context->builder->add(
                    $context->builder->mul($i64->constInt(153, false), $mShift),
                    $i64->constInt(2, false)
                ),
                $i64->constInt(5, false)
            ),
            $context->builder->sub($day, $i64->constInt(1, false))
        );
        $doe = $context->builder->add(
            $context->builder->add(
                $context->builder->mul($yoe, $i64->constInt(365, false)),
                $context->builder->sub(
                    $context->builder->signedDiv($yoe, $i64->constInt(4, false)),
                    $context->builder->signedDiv($yoe, $i64->constInt(100, false))
                )
            ),
            $doy
        );

        return $context->builder->sub(
            $context->builder->add(
                $context->builder->mul($era, $i64->constInt(146097, false)),
                $doe
            ),
            $i64->constInt(719468, false)
        );
    }

    /**
     * UTC civil breakdown (Howard Hinnant days_from_civil inverse).
     *
     * @return array{year:Value,month:Value,day:Value,hour:Value,minute:Value,second:Value,wday:Value,yday:Value}
     */
    private static function civilParts(Context $context, Value $timestamp): array
    {
        $i64 = $context->getTypeFromString('int64');
        $daySec = $i64->constInt(86400, false);
        $days = $context->builder->signedDiv($timestamp, $daySec);
        $rem = $context->builder->signedRem($timestamp, $daySec);
        $negRem = $context->builder->icmp(
            Builder::INT_SLT,
            $rem,
            $i64->constInt(0, false)
        );
        $daysAdj = BasicBlockHelper::append($context, 'gd_days_adj');
        $daysOk = BasicBlockHelper::append($context, 'gd_days_ok');
        $daysMerge = BasicBlockHelper::append($context, 'gd_days_merge');
        $remAdj = BasicBlockHelper::append($context, 'gd_rem_adj');
        $remOk = BasicBlockHelper::append($context, 'gd_rem_ok');
        $remMerge = BasicBlockHelper::append($context, 'gd_rem_merge');
        $context->builder->branchIf($negRem, $daysAdj, $daysOk);

        $context->builder->positionAtEnd($daysAdj);
        $daysMinus = $context->builder->sub($days, $i64->constInt(1, false));
        $context->builder->branch($daysMerge);
        $context->builder->positionAtEnd($daysOk);
        $context->builder->branch($daysMerge);
        $context->builder->positionAtEnd($daysMerge);
        $daysPhi = $context->builder->phi($i64);
        $daysPhi->addIncoming($daysMinus, $daysAdj);
        $daysPhi->addIncoming($days, $daysOk);
        $context->builder->branchIf($negRem, $remAdj, $remOk);

        $context->builder->positionAtEnd($remAdj);
        $remPlus = $context->builder->add($rem, $daySec);
        $context->builder->branch($remMerge);
        $context->builder->positionAtEnd($remOk);
        $context->builder->branch($remMerge);
        $context->builder->positionAtEnd($remMerge);
        $remPhi = $context->builder->phi($i64);
        $remPhi->addIncoming($remPlus, $remAdj);
        $remPhi->addIncoming($rem, $remOk);

        $c3600 = $i64->constInt(3600, false);
        $c60 = $i64->constInt(60, false);
        $hour = $context->builder->signedDiv($remPhi, $c3600);
        $rem2 = $context->builder->signedRem($remPhi, $c3600);
        $minute = $context->builder->signedDiv($rem2, $c60);
        $second = $context->builder->signedRem($rem2, $c60);

        // civilYmdPacked
        $z = $context->builder->add($daysPhi, $i64->constInt(719468, false));
        $zNeg = $context->builder->icmp(Builder::INT_SLT, $z, $i64->constInt(0, false));
        $zEraNeg = BasicBlockHelper::append($context, 'gd_z_era_neg');
        $zEraPos = BasicBlockHelper::append($context, 'gd_z_era_pos');
        $zEraMerge = BasicBlockHelper::append($context, 'gd_z_era_merge');
        $context->builder->branchIf($zNeg, $zEraNeg, $zEraPos);
        $context->builder->positionAtEnd($zEraNeg);
        $zAdj = $context->builder->sub($z, $i64->constInt(146096, false));
        $eraNeg = $context->builder->signedDiv($zAdj, $i64->constInt(146097, false));
        $context->builder->branch($zEraMerge);
        $context->builder->positionAtEnd($zEraPos);
        $eraPos = $context->builder->signedDiv($z, $i64->constInt(146097, false));
        $context->builder->branch($zEraMerge);
        $context->builder->positionAtEnd($zEraMerge);
        $era = $context->builder->phi($i64);
        $era->addIncoming($eraNeg, $zEraNeg);
        $era->addIncoming($eraPos, $zEraPos);

        $doe = $context->builder->sub($z, $context->builder->mul($era, $i64->constInt(146097, false)));
        $doe1460 = $context->builder->signedDiv($doe, $i64->constInt(1460, false));
        $doe36524 = $context->builder->signedDiv($doe, $i64->constInt(36524, false));
        $doe146096 = $context->builder->signedDiv($doe, $i64->constInt(146096, false));
        // yoe = (doe - doe/1460 + doe/36524 - doe/146096) / 365
        $yoeNum = $context->builder->sub(
            $context->builder->add($doe, $doe36524),
            $context->builder->add($doe1460, $doe146096)
        );
        $yoe = $context->builder->signedDiv($yoeNum, $i64->constInt(365, false));
        $y = $context->builder->add($yoe, $context->builder->mul($era, $i64->constInt(400, false)));
        $doy = $context->builder->sub(
            $doe,
            $context->builder->add(
                $context->builder->mul($i64->constInt(365, false), $yoe),
                $context->builder->sub(
                    $context->builder->signedDiv($yoe, $i64->constInt(4, false)),
                    $context->builder->signedDiv($yoe, $i64->constInt(100, false))
                )
            )
        );
        $mp = $context->builder->signedDiv(
            $context->builder->add($context->builder->mul($i64->constInt(5, false), $doy), $i64->constInt(2, false)),
            $i64->constInt(153, false)
        );
        $d = $context->builder->add(
            $context->builder->sub(
                $doy,
                $context->builder->signedDiv(
                    $context->builder->add($context->builder->mul($i64->constInt(153, false), $mp), $i64->constInt(2, false)),
                    $i64->constInt(5, false)
                )
            ),
            $i64->constInt(1, false)
        );
        $mpLt10 = $context->builder->icmp(Builder::INT_SLT, $mp, $i64->constInt(10, false));
        $mThen = BasicBlockHelper::append($context, 'gd_m_then');
        $mElse = BasicBlockHelper::append($context, 'gd_m_else');
        $mMerge = BasicBlockHelper::append($context, 'gd_m_merge');
        $context->builder->branchIf($mpLt10, $mThen, $mElse);
        $context->builder->positionAtEnd($mThen);
        $mA = $context->builder->add($mp, $i64->constInt(3, false));
        $context->builder->branch($mMerge);
        $context->builder->positionAtEnd($mElse);
        $mB = $context->builder->sub($mp, $i64->constInt(9, false));
        $context->builder->branch($mMerge);
        $context->builder->positionAtEnd($mMerge);
        $m = $context->builder->phi($i64);
        $m->addIncoming($mA, $mThen);
        $m->addIncoming($mB, $mElse);

        $mLe2 = $context->builder->icmp(Builder::INT_SLE, $m, $i64->constInt(2, false));
        $yInc = BasicBlockHelper::append($context, 'gd_y_inc');
        $yKeep = BasicBlockHelper::append($context, 'gd_y_keep');
        $yMerge = BasicBlockHelper::append($context, 'gd_y_merge');
        $context->builder->branchIf($mLe2, $yInc, $yKeep);
        $context->builder->positionAtEnd($yInc);
        $yPlus = $context->builder->add($y, $i64->constInt(1, false));
        $context->builder->branch($yMerge);
        $context->builder->positionAtEnd($yKeep);
        $context->builder->branch($yMerge);
        $context->builder->positionAtEnd($yMerge);
        $year = $context->builder->phi($i64);
        $year->addIncoming($yPlus, $yInc);
        $year->addIncoming($y, $yKeep);

        // Sakamoto weekday
        $monLt3 = $context->builder->icmp(Builder::INT_SLT, $m, $i64->constInt(3, false));
        $ywAdj = BasicBlockHelper::append($context, 'gd_yw_adj');
        $ywOk = BasicBlockHelper::append($context, 'gd_yw_ok');
        $ywMerge = BasicBlockHelper::append($context, 'gd_yw_merge');
        $context->builder->branchIf($monLt3, $ywAdj, $ywOk);
        $context->builder->positionAtEnd($ywAdj);
        $yWMinus = $context->builder->sub($year, $i64->constInt(1, false));
        $context->builder->branch($ywMerge);
        $context->builder->positionAtEnd($ywOk);
        $context->builder->branch($ywMerge);
        $context->builder->positionAtEnd($ywMerge);
        $yW = $context->builder->phi($i64);
        $yW->addIncoming($yWMinus, $ywAdj);
        $yW->addIncoming($year, $ywOk);

        // t table via select chain for mon 1..12
        $t = self::sakamotoT($context, $m);
        $wSum = $context->builder->add(
            $context->builder->add(
                $yW,
                $context->builder->signedDiv($yW, $i64->constInt(4, false))
            ),
            $context->builder->add(
                $context->builder->sub(
                    $context->builder->signedDiv($yW, $i64->constInt(400, false)),
                    $context->builder->signedDiv($yW, $i64->constInt(100, false))
                ),
                $context->builder->add($t, $d)
            )
        );
        $wday = $context->builder->signedRem($wSum, $i64->constInt(7, false));

        $yday = self::dayOfYearLlvm($context, $year, $m, $d);

        return [
            'year' => $year,
            'month' => $m,
            'day' => $d,
            'hour' => $hour,
            'minute' => $minute,
            'second' => $second,
            'wday' => $wday,
            'yday' => $yday,
        ];
    }

    private static function sakamotoT(Context $context, Value $mon): Value
    {
        $i64 = $context->getTypeFromString('int64');
        // Default t=4 (December)
        $t = $i64->constInt(4, false);
        $table = [1 => 0, 2 => 3, 3 => 2, 4 => 5, 5 => 0, 6 => 3, 7 => 5, 8 => 1, 9 => 4, 10 => 6, 11 => 2, 12 => 4];
        foreach ($table as $m => $tv) {
            $is = $context->builder->icmp(Builder::INT_EQ, $mon, $i64->constInt($m, false));
            $t = $context->builder->select($is, $i64->constInt($tv, false), $t);
        }

        return $t;
    }

    private static function dayOfYearLlvm(Context $context, Value $year, Value $mon, Value $mday): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $yday = $context->builder->sub($mday, $i64->constInt(1, false));
        $leap = self::isLeapLlvm($context, $year);
        // if mon > N add days-in-month-N (same order as GetdateJitHelper::dayOfYear)
        $adds = [
            1 => 31,
            2 => -1, // leap-sensitive
            3 => 31,
            4 => 30,
            5 => 31,
            6 => 30,
            7 => 31,
            8 => 31,
            9 => 30,
            10 => 31,
            11 => 30,
        ];
        foreach ($adds as $prevMon => $days) {
            $gt = $context->builder->icmp(Builder::INT_SGT, $mon, $i64->constInt($prevMon, false));
            if (-1 === $days) {
                $feb = $context->builder->select($leap, $i64->constInt(29, false), $i64->constInt(28, false));
                $inc = $context->builder->select($gt, $feb, $i64->constInt(0, false));
            } else {
                $inc = $context->builder->select($gt, $i64->constInt($days, false), $i64->constInt(0, false));
            }
            $yday = $context->builder->add($yday, $inc);
        }

        return $yday;
    }

    private static function isLeapLlvm(Context $context, Value $year): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $mod4 = $context->builder->signedRem($year, $i64->constInt(4, false));
        $mod100 = $context->builder->signedRem($year, $i64->constInt(100, false));
        $mod400 = $context->builder->signedRem($year, $i64->constInt(400, false));
        $div4 = $context->builder->icmp(Builder::INT_EQ, $mod4, $i64->constInt(0, false));
        $not100 = $context->builder->icmp(Builder::INT_NE, $mod100, $i64->constInt(0, false));
        $div400 = $context->builder->icmp(Builder::INT_EQ, $mod400, $i64->constInt(0, false));

        return $context->builder->or(
            $context->builder->and($div4, $not100),
            $div400
        );
    }

    private static function setLong(Context $context, Value $ht, string $key, Value $long): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            $long
        );
    }

    private static function setWeekdayName(Context $context, Value $ht, Value $wday): void
    {
        $key = $context->builder->load($context->constantStringFromString('weekday'));
        $setFn = $context->lookupFunction('__hashtable__setStringKeyString');
        $i64 = $context->getTypeFromString('int64');
        $chosen = $context->builder->load($context->constantStringFromString('Saturday'));
        foreach (self::WEEKDAYS as $i => $name) {
            $is = $context->builder->icmp(Builder::INT_EQ, $wday, $i64->constInt($i, false));
            $str = $context->builder->load($context->constantStringFromString($name));
            $chosen = $context->builder->select($is, $str, $chosen);
        }
        $context->builder->call($setFn, $ht, $key, $chosen);
    }

    private static function setMonthName(Context $context, Value $ht, Value $mon): void
    {
        $key = $context->builder->load($context->constantStringFromString('month'));
        $setFn = $context->lookupFunction('__hashtable__setStringKeyString');
        $i64 = $context->getTypeFromString('int64');
        $chosen = $context->builder->load($context->constantStringFromString('January'));
        foreach (self::MONTHS as $i => $name) {
            $is = $context->builder->icmp(Builder::INT_EQ, $mon, $i64->constInt($i + 1, false));
            $str = $context->builder->load($context->constantStringFromString($name));
            $chosen = $context->builder->select($is, $str, $chosen);
        }
        $context->builder->call($setFn, $ht, $key, $chosen);
    }
}
