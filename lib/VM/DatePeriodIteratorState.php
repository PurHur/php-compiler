<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/** Foreach / Iterator cursor for DatePeriod (#14228, php-src ext/date/php_date.c). */
final class DatePeriodIteratorState
{
    public int $key = 0;

    public bool $started = false;
}
