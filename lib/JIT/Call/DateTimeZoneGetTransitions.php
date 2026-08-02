<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitTimezoneTransitionsGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTimeZone::getTransitions(?int $timestamp_begin, ?int $timestamp_end) — JIT/AOT (#26799).
 *
 * php-src: ext/date/php_date.c — zim_DateTimeZone_getTransitions
 * Shares compile-time materialize path with {@see timezone_transitions_get}.
 */
final class DateTimeZoneGetTransitions implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitTimezoneTransitionsGet::invokeMethod($context, ...$args);
    }
}
