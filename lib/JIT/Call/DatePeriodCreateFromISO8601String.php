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
    /** @var list<int>|null */
    public ?array $lastCompileTimeDatePeriodTimestamps = null;

    public ?string $lastCompileTimeDatePeriodTimezone = null;

    public function call(Context $context, Variable ...$args): Value
    {
        $this->lastCompileTimeDatePeriodTimestamps = null;
        $this->lastCompileTimeDatePeriodTimezone = null;
        $result = JitDatePeriodCreateFromISO8601String::invoke($context, ...$args);
        $snap = JitDatePeriodCreateFromISO8601String::takeLastCompileTimeForeachSnapshot();
        if (null !== $snap) {
            $this->lastCompileTimeDatePeriodTimestamps = $snap['timestamps'];
            $this->lastCompileTimeDatePeriodTimezone = $snap['timezone'];
        }

        return $result;
    }
}
