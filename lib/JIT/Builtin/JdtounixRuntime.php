<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for jdtounix() via JdtounixJitHelper PHP (#27387).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer gregoriantojd #27386).
 * SSOT: {@see \PHPCompiler\ext\calendar\VmCalendar}.
 * php-src: ext/calendar/cal_unix.c — PHP_FUNCTION(jdtounix)
 */
final class JdtounixRuntime
{
    private const ABI = 'phpc_jdtounix';

    private const HELPER_PATH = '/ext/calendar/JdtounixJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\calendar\\JdtounixJitHelper::jdtounixArgv';

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

    public static function invoke(Context $context, Value $julianDay): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $julianDay
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
                '#27387'
            );
        }
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'jdtounix_bridge_entry',
            [$i64],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27387'
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
