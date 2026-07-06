<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDatePeriodCreateFromISO8601String;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DatePeriod::createFromISO8601String() — JIT/AOT (#7296, #16796, ext/date/php_date.c). */
final class DatePeriodCreateFromISO8601String implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDatePeriodCreateFromISO8601String::invoke($context, ...$args);
    }
}
