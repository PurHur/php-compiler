<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Discarded pure-call elision for date / mktime / strtotime / date_parse /
 * date_sun_info / timezone abbr / calendar↔jd / easter / jdtojewish /
 * jdtounix / unixtojd builtins (#36387 / #23483).
 *
 * Extracted from {@see DiscardedPureCallElision} so the 5k-line hub is not
 * one monolith TU on the gen-0 spine (split-TU / size-budget ratchet). Shared
 * arg predicates ({@code stringArgAllowsDiscardedElision},
 * {@code mathArgAllowsDiscardedElision}) stay on the hub class.
 *
 * Used via {@code use DiscardedPureCallElisionDateCalOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: ext/date/php_date.c, ext/calendar/calendar.c,
 * ext/calendar/dow.c, ext/calendar/easter.c, ext/calendar/cal_unix.c.
 */
trait DiscardedPureCallElisionDateCalOps
{
    /**
     * Discarded {@code date}/{@code gmdate} — php-src {@code ext/date/php_date.c}.
     * Typed format string; optional typed-or-null timestamp. Soft-null format
     * stays live (deprecate). Excess argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDateFormatRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('date' !== $name && 'gmdate' !== $name) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[2])) {
            return false;
        }
        if (!self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (!$callArgs[1] instanceof Variable) {
            return false;
        }
        // Z_PARAM_LONG_OR_NULL — explicit null means "now"; soft-null format already excluded.
        if ($callArgs[1]->isNullConstant || Variable::TYPE_NULL === $callArgs[1]->type) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($callArgs[1]);
    }

    /**
     * Discarded {@code mktime}/{@code gmmktime} — php-src {@code ext/date/php_date.c}.
     * 1..6 typed numeric civil parts; hour required non-null; optional null
     * components OK ({@code ?int}). Soft-null hour / string / object stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMktimeRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('mktime' !== $name && 'gmmktime' !== $name) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 1 || $argc > 6) {
            return false;
        }
        foreach ($callArgs as $i => $arg) {
            if (!$arg instanceof Variable) {
                return false;
            }
            if (0 === $i) {
                // Required hour — soft-null deprecates / TypeErrors under strict.
                if (!self::mktimeNumericArgAllowsDiscardedElision($arg)) {
                    return false;
                }
                continue;
            }
            // Optional ?int — explicit null OK; soft-null / string / object stay live.
            if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
                continue;
            }
            if (!self::mktimeNumericArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code strtotime} — php-src {@code ext/date/php_date.c}.
     * Typed datetime string; optional typed-or-null base timestamp. Soft-null
     * datetime stays live (deprecate). Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStrtotimeRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('strtotime' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[2])) {
            return false;
        }
        if (!self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (!$callArgs[1] instanceof Variable) {
            return false;
        }
        // Z_PARAM_LONG_OR_NULL — explicit null means "now".
        if ($callArgs[1]->isNullConstant || Variable::TYPE_NULL === $callArgs[1]->type) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($callArgs[1]);
    }

    /**
     * Discarded {@code date_parse}/{@code date_parse_from_format} — php-src
     * {@code ext/date/php_date.c}. Typed string args only. Soft-null stays live
     * (deprecate / TypeError). Wrong argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDateParseRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('date_parse' === $name) {
            if (1 !== \count($callArgs) || !$callArgs[0] instanceof Variable) {
                return false;
            }

            return self::stringArgAllowsDiscardedElision($callArgs[0]);
        }
        if ('date_parse_from_format' !== $name) {
            return false;
        }
        if (2 !== \count($callArgs)
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }

        return self::stringArgAllowsDiscardedElision($callArgs[0])
            && self::stringArgAllowsDiscardedElision($callArgs[1]);
    }

    /**
     * Discarded {@code date_sun_info} — php-src {@code ext/date/php_date.c}.
     * Exactly three typed numerics (timestamp / latitude / longitude). Soft-null
     * / non-numeric stay live ({@code TypeError} / deprecate). Wrong argc stays
     * live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDateSunInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('date_sun_info' !== strtolower($toCall->getName())) {
            return false;
        }
        if (3 !== \count($callArgs)) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code timezone_name_from_abbr} — php-src {@code ext/date/php_date.c}.
     * Typed abbr string + optional typed {@code gmtoffset}/{@code isdst} longs.
     * Soft-null stays live (deprecate). Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureTimezoneNameFromAbbrNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('timezone_name_from_abbr' !== strtolower($toCall->getName())) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 1 || $argc > 3) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        for ($i = 1; $i < $argc; ++$i) {
            if (
                !$callArgs[$i] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[$i])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code gregoriantojd}/{@code juliantojd}/{@code jewishtojd}/
     * {@code frenchtojd} — php-src {@code ext/calendar/calendar.c}. Exactly
     * three typed numerics (month / day / year). Soft-null / non-numeric stay
     * live ({@code TypeError} / deprecate). Wrong argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalendarToJdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        switch (strtolower($toCall->getName())) {
            case 'gregoriantojd':
            case 'juliantojd':
            case 'jewishtojd':
            case 'frenchtojd':
                break;
            default:
                return false;
        }
        if (3 !== \count($callArgs)) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code cal_days_in_month} — php-src {@code ext/calendar/calendar.c}.
     * Compile-time calendar id in {@code [0, CAL_NUM_CALS)} (php-src
     * {@code CAL_NUM_CALS == 4}) plus two typed numerics (month / year).
     * Runtime / invalid calendar stays live ({@code ValueError}). Soft-null /
     * wrong argc stay live (deprecate / {@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalDaysInMonthNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('cal_days_in_month' !== strtolower($toCall->getName())) {
            return false;
        }
        if (3 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
            || $callArgs[0]->compileTimeLong < 0
            || $callArgs[0]->compileTimeLong >= 4
        ) {
            return false;
        }
        for ($i = 1; $i < 3; ++$i) {
            if (
                !$callArgs[$i] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[$i])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code jdtogregorian}/{@code jdtojulian}/{@code jdtofrench} —
     * php-src {@code ext/calendar/calendar.c}. Exactly one typed numeric
     * (julian day). Soft-null / non-numeric stay live ({@code TypeError} /
     * deprecate). Wrong argc stays live ({@code ArgumentCountError}).
     * {@code jdtojewish}/{@code jdtounix} have dedicated handlers below
     * (hebrew/flags and unix-range {@code ValueError} paths).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalendarFromJdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        switch (strtolower($toCall->getName())) {
            case 'jdtogregorian':
            case 'jdtojulian':
            case 'jdtofrench':
                break;
            default:
                return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }

        return $callArgs[0] instanceof Variable
            && self::mathArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * Discarded {@code jdmonthname} — php-src {@code ext/calendar/calendar.c}.
     * Exactly two typed numerics (julian day / mode). Soft-null / wrong argc
     * stay live (deprecate / {@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJdMonthNameNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('jdmonthname' !== strtolower($toCall->getName())) {
            return false;
        }
        if (2 !== \count($callArgs)) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code jddayofweek} — php-src {@code ext/calendar/dow.c}.
     * One or two typed numerics (julian day + optional mode). Soft-null /
     * wrong argc stay live (deprecate / {@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJdDayOfWeekNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('jddayofweek' !== strtolower($toCall->getName())) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 1 || $argc > 2) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code cal_from_jd} — php-src {@code ext/calendar/calendar.c}.
     * Typed julian day + compile-time calendar id in {@code [0, CAL_NUM_CALS)}.
     * Runtime / invalid calendar stays live ({@code ValueError}). Soft-null /
     * wrong argc stay live (deprecate / {@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalFromJdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('cal_from_jd' !== strtolower($toCall->getName())) {
            return false;
        }
        if (2 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || !self::mathArgAllowsDiscardedElision($callArgs[0])
        ) {
            return false;
        }
        if (
            !$callArgs[1] instanceof Variable
            || null === $callArgs[1]->compileTimeLong
            || $callArgs[1]->compileTimeLong < 0
            || $callArgs[1]->compileTimeLong >= 4
        ) {
            return false;
        }

        return true;
    }

    /**
     * Discarded {@code cal_to_jd} — php-src {@code ext/calendar/calendar.c}.
     * Compile-time calendar id in {@code [0, CAL_NUM_CALS)} plus three typed
     * numerics (month / day / year). Runtime / invalid calendar stays live
     * ({@code ValueError}). Soft-null / wrong argc stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalToJdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('cal_to_jd' !== strtolower($toCall->getName())) {
            return false;
        }
        if (4 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
            || $callArgs[0]->compileTimeLong < 0
            || $callArgs[0]->compileTimeLong >= 4
        ) {
            return false;
        }
        for ($i = 1; $i < 4; ++$i) {
            if (
                !$callArgs[$i] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[$i])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code cal_info} — php-src {@code ext/calendar/calendar.c}.
     * Zero-arg (all calendars) or compile-time calendar id {@code -1} /
     * {@code [0, CAL_NUM_CALS)}. Runtime / invalid calendar stays live
     * ({@code ValueError}). Soft-null / excess argc stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('cal_info' !== strtolower($toCall->getName())) {
            return false;
        }
        $argc = \count($callArgs);
        if (0 === $argc) {
            return true;
        }
        if (1 !== $argc) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
        ) {
            return false;
        }
        $cal = $callArgs[0]->compileTimeLong;

        return -1 === $cal || ($cal >= 0 && $cal < 4);
    }

    /**
     * Discarded {@code easter_days}/{@code easter_date} — php-src
     * {@code ext/calendar/easter.c}. Compile-time year inside the php-src
     * {@code ValueError} window plus optional typed mode. Zero-arg /
     * soft-null year stay live (current-year clock). Runtime year stays live
     * ({@code ValueError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureEasterNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('easter_days' !== $name && 'easter_date' !== $name) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 1 || $argc > 2) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
        ) {
            return false;
        }
        $year = $callArgs[0]->compileTimeLong;
        $maxYear = intdiv(\PHP_INT_MAX, 5) * 4;
        if ($year <= 0 || $year > $maxYear) {
            return false;
        }
        if ('easter_date' === $name) {
            if (\PHP_INT_SIZE >= 8) {
                if ($year < 1970 || $year > 2000000000) {
                    return false;
                }
            } elseif ($year < 1970 || $year > 2037) {
                return false;
            }
        }
        if (2 === $argc) {
            if (
                !$callArgs[1] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[1])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code jdtojewish} — php-src {@code ext/calendar/calendar.c}.
     * Exactly one typed numeric (hebrew defaults false). Hebrew / flags forms
     * stay live (optional formatting {@code ValueError} paths). Soft-null /
     * wrong argc stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJdtojewishNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('jdtojewish' !== strtolower($toCall->getName())) {
            return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }

        return $callArgs[0] instanceof Variable
            && self::mathArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * Discarded {@code jdtounix} — php-src {@code ext/calendar/cal_unix.c}.
     * Compile-time julian day in {@code [UNIX_EPOCH_JD, UNIX_EPOCH_JD +
     * PHP_INT_MAX/86400]}. Runtime / out-of-range stay live ({@code ValueError}).
     * Soft-null / wrong argc stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJdtounixNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('jdtounix' !== strtolower($toCall->getName())) {
            return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
        ) {
            return false;
        }
        $jd = $callArgs[0]->compileTimeLong;
        $epochJd = 2440588;
        $maxJd = $epochJd + intdiv(\PHP_INT_MAX, 86400);

        return $jd >= $epochJd && $jd <= $maxJd;
    }

    /**
     * Discarded {@code unixtojd} — php-src {@code ext/calendar/cal_unix.c}.
     * Exactly one compile-time timestamp ≥ 0 (oversized timestamps return
     * false — discarded). Zero-arg / soft-null stay live ({@code time()} /
     * deprecate). Negative / runtime stay live ({@code ValueError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureUnixtojdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('unixtojd' !== strtolower($toCall->getName())) {
            return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
        ) {
            return false;
        }

        return $callArgs[0]->compileTimeLong >= 0;
    }

    /**
     * mktime/gmmktime civil parts — typed long/double/bool / compile-time number.
     * Numeric string literals stay live (our VM TypeErrors; Zend Z_PARAM_LONG
     * coerces — keep discarded-elision conservative on strings).
     */
    private static function mktimeNumericArgAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return false;
        }
        if (null !== $arg->compileTimeLong || null !== $arg->compileTimeFloat) {
            return true;
        }

        return Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_NATIVE_DOUBLE === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type;
    }
}
