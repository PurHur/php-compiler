<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for unixtojd() via UnixtojdJitHelper PHP (#27367).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer jdtounix #27387).
 * NestedJIT SSOT: {@see \PHPCompiler\ext\calendar\UnixtojdJitHelper}
 * VM SSOT: {@see \PHPCompiler\ext\calendar\VmCalendar::unixtojd()}
 * php-src: ext/calendar/cal_unix.c — PHP_FUNCTION(unixtojd)
 */
final class UnixtojdRuntime
{
    private const ABI = 'phpc_unixtojd';

    private const HELPER_PATH = '/ext/calendar/UnixtojdJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\calendar\\UnixtojdJitHelper::unixtojdArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    /** @var list<string> */
    private const BUNDLE_PATHS = [
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

    public static function invoke(Context $context, Value $timestamp): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $timestamp
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
                '#27367'
            );
        }
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'unixtojd_bridge_entry',
            [$i64],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27367'
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
