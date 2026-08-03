<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitTimezoneOffsetGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTimeZone::getOffset(DateTimeInterface $datetime) — JIT/AOT (#27308).
 *
 * php-src: ext/date/php_date.c — zim_DateTimeZone_getOffset / timezone_offset_get
 * Shares lowering with {@see timezone_offset_get}.
 */
final class DateTimeZoneGetOffset implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitTimezoneOffsetGet::invokeMethod($context, ...$args);
    }
}
