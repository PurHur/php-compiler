<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitTimezoneNameGet;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTimeZone::getName() — JIT/AOT (#27307).
 *
 * php-src: ext/date/php_date.c — zim_DateTimeZone_getName / timezone_name_get
 * Shares lowering with {@see timezone_name_get}.
 */
final class DateTimeZoneGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitTimezoneNameGet::invokeMethod($context, ...$args);
    }
}
