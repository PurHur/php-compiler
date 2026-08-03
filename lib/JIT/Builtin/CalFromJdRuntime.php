<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\calendar\CalFromJdJitHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for cal_from_jd() via CalFromJdJitHelper PHP (#27359).
 *
 * Compile-time JD+calendar: embed via {@see HashTableHelper::variableFromVmHashTable}
 * (peer cal_info #27354 — NestedJIT HashTable alone can yield empty dim under thin AOT).
 * Runtime: NestedJIT bridge returning `__hashtable__*` (peer str_word_count words).
 *
 * SSOT: {@see CalFromJdJitHelper} → {@see \PHPCompiler\ext\calendar\VmCalendar}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(cal_from_jd)
 */
final class CalFromJdRuntime
{
    private const ABI = 'phpc_cal_from_jd';

    private const HELPER_PATH = '/ext/calendar/CalFromJdJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\calendar\\CalFromJdJitHelper::calFromJdArgv';

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

    /** Compile-time JD + calendar — embed breakdown table. */
    public static function emit(Context $context, int $julianDay, int $calendar): Value
    {
        $ht = CalFromJdJitHelper::calFromJdArgv($julianDay, $calendar);

        return HashTableHelper::variableFromVmHashTable($context, $ht)->value;
    }

    public static function invoke(Context $context, Value $julianDay, Value $calendar): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $julianDay,
            $calendar
        );
    }

    private static function implement(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        if (self::helpersMissing($context)) {
            JitVmHelperLink::ensureCompiledBundle(
                $context,
                self::BUNDLE_PATHS,
                self::COMPILED_HELPERS,
                '#27359'
            );
        }
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'cal_from_jd_bridge_entry',
            [$i64, $i64],
            $htPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27359'
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
