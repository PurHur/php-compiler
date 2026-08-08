<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Builtin\DateTimeFormatRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for IntlDateFormatter::format() / datefmt_format() (#27361 / re-#20837).
 *
 * Reads {@see IntlDateFormatterFormatJitHelper} props from create; formats via
 * {@see DateTimeFormatRuntime} (same NestedJIT civil path as DateTime::format).
 *
 * php-src: ext/intl/dateformat/dateformat_format.c — zim_IntlDateFormatter_format
 */
final class JitIntlDateFormatterFormat
{
    /**
     * @param list<JITVariable> $args datefmt_format($formatter, $datetime)
     */
    public static function invokeProcedural(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_format() expects exactly 2 arguments, %d given',
                $argc
            ));
        }

        return self::invokePair($context, $args[0], $args[1], 'datefmt_format');
    }

    /**
     * @param list<JITVariable> $args IntlDateFormatter::format($datetime) — $this first
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::format() expects exactly 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }

        return self::invokePair($context, $args[0], $args[1], 'IntlDateFormatter::format');
    }

    private static function invokePair(
        Context $context,
        JITVariable $receiver,
        JITVariable $datetimeArg,
        string $function
    ): Value {
        $phpFmtCt = JitIntlDateFormatterCreate::takeLastCompileTimePhpFormat();
        $tzCt = JitIntlDateFormatterCreate::takeLastCompileTimeTimezone() ?? 'UTC';

        // Prefer CT format/timezone from create; else load props from the receiver object.
        // Always format at runtime via DateTimeFormatRuntime (strtotime() is a value-box).
        if (null !== $phpFmtCt && '' !== $phpFmtCt) {
            $phpFormatPtr = $context->builder->load($context->constantStringFromString($phpFmtCt));
            $tzPtr = $context->builder->load($context->constantStringFromString($tzCt));
        } else {
            $obj = ReflectionSetup::loadObjectFromArg($context, $receiver);
            $objectType = $context->type->object;
            $phpFormatVar = $objectType->propertyFetch(
                $obj,
                'IntlDateFormatter',
                IntlDateFormatterFormatJitHelper::PROP_PHP_FORMAT
            );
            $tzVar = $objectType->propertyFetch(
                $obj,
                'IntlDateFormatter',
                IntlDateFormatterFormatJitHelper::PROP_TIMEZONE
            );
            $phpFormatPtr = $context->helper->loadValue($phpFormatVar);
            $tzPtr = $context->helper->loadValue($tzVar);
        }

        $timestamp = JitLongArg::lower($context, $datetimeArg, $function.' datetime');
        $microsecond = $context->constantFromInteger(0);
        $raw = DateTimeFormatRuntime::invoke(
            $context,
            $phpFormatPtr,
            $timestamp,
            $microsecond,
            $tzPtr
        );

        return self::boxRaw($context, $raw);
    }

    private static function boxRaw(Context $context, Value $raw): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
