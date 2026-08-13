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

        return DateTimeFormatJitHelper::compileFormat($context, $args[0], $args[1]);
    }
}
