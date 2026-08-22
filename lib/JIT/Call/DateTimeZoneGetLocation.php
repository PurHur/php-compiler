<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitTimezoneLocationGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTimeZone::getLocation() — JIT/AOT (#33727).
 *
 * php-src: ext/date/php_date.c — zim_DateTimeZone_getLocation / timezone_location_get
 * Shares lowering with {@see timezone_location_get}.
 */
final class DateTimeZoneGetLocation implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitTimezoneLocationGet::invokeMethod($context, ...$args);
    }
}
