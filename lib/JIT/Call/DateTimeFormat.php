<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DateTimeFormatJitHelper;
use PHPLLVM\Value;

/** DateTime/DateTimeImmutable::format(string $format) — JIT/AOT (#4043, #30834). */
final class DateTimeFormat implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $argc = \count($args);
        // User arity excludes $this — php-src zim_DateTime_format (#30834).
        if (2 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'DateTime::format() expects exactly 1 argument, %d given',
                    max(0, $argc - 1)
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'datetime_format_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::pointer($context, $slot);
        }

        // Unserialize sync stamps named bindings / dateTimeLocalInstants; method $this may
        // be a divergent scope Variable (#34614). Restore before format fold.
        self::restoreDateTimeInstantStamps($context, $args[0]);

        return DateTimeFormatJitHelper::compileFormat($context, $args[0], $args[1]);
    }

    /**
     * Copy compile-time instant onto $this when scope Variable lost unserialize stamps (#34614).
     */
    private static function restoreDateTimeInstantStamps(Context $context, Variable $receiver): void
    {
        if (null !== $receiver->compileTimeDateTimeTimestamp) {
            return;
        }
        $instant = null;
        $last = $context->lastDateTimeUnserializeLocalName;
        // Only restore the unserialize target — a single construct local must not stamp
        // unrelated DateTimeImmutable mutation returns (#34651 / re-#34614).
        if (\is_string($last) && '' !== $last && isset($context->dateTimeLocalInstants[$last])) {
            $instant = $context->dateTimeLocalInstants[$last];
        }
        if (!\is_array($instant) || !isset($instant['timestamp'])) {
            return;
        }
        $receiver->compileTimeDateTimeTimestamp = (int) $instant['timestamp'];
        $receiver->compileTimeDateTimeMicrosecond = (int) ($instant['microsecond'] ?? 0);
        $receiver->compileTimeTimezoneName = $instant['timezone'] ?? null;
        if (null === $receiver->classUserType || '' === $receiver->classUserType) {
            $receiver->classUserType = 'DateTime';
        }
    }
}
