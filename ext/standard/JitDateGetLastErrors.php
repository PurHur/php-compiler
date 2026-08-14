<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * LLVM lowering for date_get_last_errors() / DateTime(Immutable)::getLastErrors() (#30749).
 *
 * Snapshots {@see DateTimeSupport::peekCreateFromFormatLastErrors} at compile time — the same
 * slot updated by date_create_from_format / DateTime::createFromFormat lowering — and emits a
 * constant false or hashtable (peer date_parse materialize, #6172).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_get_last_errors) / zim_DateTime_getLastErrors
 */
final class JitDateGetLastErrors
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('date_get_last_errors() expects exactly 0 arguments, %d given', $argc)
            );
        }

        return self::materializePeek($context);
    }

    /** Static method Call path — no implicit $this (#30749). */
    public static function invokeMethod(Context $context, string $function, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError under JIT/AOT try/catch (#30991; peer #30898).
        if (0 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('%s() expects exactly 0 arguments, %d given', $function, $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_argc_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return self::materializePeek($context);
    }

    private static function materializePeek(Context $context): Value
    {
        $bag = DateTimeSupport::peekCreateFromFormatLastErrors();
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $bag) {
            JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));

            return $ptr;
        }
        $ht = JitDateParseMaterializer::materializeLastErrors($context, $bag);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }
}
