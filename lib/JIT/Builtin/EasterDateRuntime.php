<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for easter_date() via EasterDateJitHelper PHP (#27356).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer easter_days #27358).
 * NestedJIT SSOT: {@see \PHPCompiler\ext\calendar\EasterDateJitHelper}
 * VM SSOT: {@see \PHPCompiler\ext\calendar\VmCalendar::easterDate()}
 * php-src: ext/calendar/easter.c — PHP_FUNCTION(easter_date)
 */
final class EasterDateRuntime
{
    private const ABI = 'phpc_easter_date';

    private const ABI_NOW = 'phpc_easter_date_now';

    private const HELPER_PATH = '/ext/calendar/EasterDateJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\calendar\\EasterDateJitHelper::easterDateArgv';

    private const HELPER_NOW = 'PHPCompiler\\ext\\calendar\\EasterDateJitHelper::easterDateNowArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
        self::HELPER_NOW,
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

    public static function invoke(Context $context, Value $year, Value $mode): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $year,
            $mode
        );
    }

    public static function invokeCurrentYear(Context $context, Value $mode): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NOW),
            $mode
        );
    }

    private static function implement(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        if (self::helpersMissing($context)) {
            JitVmHelperLink::ensureCompiledBundle(
                $context,
                self::BUNDLE_PATHS,
                self::COMPILED_HELPERS,
                '#27356'
            );
        }
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'easter_date_bridge_entry',
            [$i64, $i64],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27356'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NOW,
            'easter_date_now_bridge_entry',
            [$i64],
            $i64,
            self::HELPER_NOW,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27356'
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
