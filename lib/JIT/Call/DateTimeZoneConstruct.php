<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateTimeZoneConstruct;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTimeZone::__construct() — JIT/AOT (#26772).
 *
 * php-src: ext/date/php_date.c — zim_DateTimeZone___construct
 */
final class DateTimeZoneConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateTimeZoneConstruct::invoke($context, ...$args);
    }
}
