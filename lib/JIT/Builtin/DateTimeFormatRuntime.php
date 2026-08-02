<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for DateTime::format() via DateTimeFormatJitHelper PHP (#4043, #26772).
 *
 * Helper-runtime unit.o stubs format → null; USER_SCRIPT_INLINE_ONLY NestedJITs the
 * NestedJIT-safe self-contained helper (#26772).
 */
final class DateTimeFormatRuntime
{
    private const ABI = 'phpc_datetime_format';

    private const HELPER_PATH = '/ext/standard/DateTimeFormatJitHelper.php';

    private const FORMAT_HELPER = 'PHPCompiler\\ext\\standard\\DateTimeFormatJitHelper::formatStateArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(
        Context $context,
        Value $formatPtr,
        Value $timestamp,
        Value $microsecond,
        Value $tzPtr
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $formatPtr,
            $timestamp,
            $microsecond,
            $tzPtr
        );
    }

    private static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'datetime_format_bridge_entry',
            [$strPtr, $i64, $i64, $strPtr],
            $strPtr,
            self::FORMAT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#4043/#26772'
        );
    }
}
