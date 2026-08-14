<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitTimezoneAbbreviationsList;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTimeZone::listAbbreviations() — JIT/AOT (#30780).
 *
 * SSOT: {@see JitTimezoneAbbreviationsList} (same as timezone_abbreviations_list()).
 * php-src: ext/date/php_date.c — PHP_METHOD(DateTimeZone, listAbbreviations)
 * Static — no implicit $this (peer DateTimeZone::listIdentifiers).
 */
final class DateTimeZoneListAbbreviations implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'DateTimeZone::listAbbreviations';

    /** @var list<string> php-src php_date.stub.php — zero-arg static. */
    public array $paramNames = [];

    /** Static method — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError under AOT try/catch (#30898; peer #30681).
        if (0 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('DateTimeZone::listAbbreviations() expects exactly 0 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'datetimezone_listabbreviations_argc_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitTimezoneAbbreviationsList::invoke($context);
    }
}
