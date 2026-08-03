<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for jewishtojd() via JewishtojdJitHelper PHP (#27357).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer cal_to_jd #27366).
 * SSOT: {@see \PHPCompiler\ext\calendar\VmJewishFrenchCalendar}.
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(jewishtojd)
 */
final class JewishtojdRuntime
{
    private const ABI = 'phpc_jewishtojd';

    private const HELPER_PATH = '/ext/calendar/JewishtojdJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\calendar\\JewishtojdJitHelper::jewishtojdArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    /** @var list<string> */
    private const BUNDLE_PATHS = [
        '/ext/calendar/CalendarConstants.php',
        '/ext/calendar/CalendarTables.php',
        '/ext/calendar/VmJewishFrenchCalendar.php',
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

    public static function invoke(
        Context $context,
        Value $month,
        Value $day,
        Value $year
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $month,
            $day,
            $year
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
                '#27357'
            );
        }
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'jewishtojd_bridge_entry',
            [$i64, $i64, $i64],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27357'
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
