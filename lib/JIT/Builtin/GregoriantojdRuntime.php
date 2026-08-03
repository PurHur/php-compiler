<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for gregoriantojd() via GregoriantojdJitHelper PHP (#27386).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer jewishtojd #27357).
 * SSOT: {@see \PHPCompiler\ext\calendar\VmCalendar}.
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(gregoriantojd)
 */
final class GregoriantojdRuntime
{
    private const ABI = 'phpc_gregoriantojd';

    private const HELPER_PATH = '/ext/calendar/GregoriantojdJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\calendar\\GregoriantojdJitHelper::gregoriantojdArgv';

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
                '#27386'
            );
        }
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'gregoriantojd_bridge_entry',
            [$i64, $i64, $i64],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27386'
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
