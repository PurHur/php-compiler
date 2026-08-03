<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for cal_days_in_month() via CalDaysInMonthJitHelper PHP (#27310).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer checkdate #26196 / asin #15130).
 * SSOT: {@see \PHPCompiler\ext\calendar\VmCalendar}.
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(cal_days_in_month)
 */
final class CalDaysInMonthRuntime
{
    private const ABI = 'phpc_cal_days_in_month';

    private const HELPER_PATH = '/ext/calendar/CalDaysInMonthJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\calendar\\CalDaysInMonthJitHelper::calDaysInMonthArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    /** @var list<string> */
    private const BUNDLE_PATHS = [
        '/ext/calendar/CalendarConstants.php',
        '/ext/calendar/CalendarTables.php',
        '/ext/calendar/VmJewishFrenchCalendar.php',
        '/ext/calendar/VmCalendar.php',
        self::HELPER_PATH,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $calendar, Value $month, Value $year): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $calendar,
            $month,
            $year
        );
    }

    private static function implement(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        // Bundle calendar math before the ABI bridge so NestedJIT can resolve VmCalendar (#27310).
        if (self::helpersMissing($context)) {
            JitVmHelperLink::ensureCompiledBundle(
                $context,
                self::BUNDLE_PATHS,
                self::COMPILED_HELPERS,
                '#27310'
            );
        }
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'cal_days_in_month_bridge_entry',
            [$i64, $i64, $i64],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27310'
        );
    }

    private static function helpersMissing(Context $context): bool
    {
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                return true;
            }
        }

        return false;
    }
}
