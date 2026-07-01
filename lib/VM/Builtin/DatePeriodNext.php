<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::next() — php-src date_period_next (#14228). */
final class DatePeriodNext extends DatePeriodIteratorMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        DatePeriodSupport::iteratorNext(self::receiver($frame));
    }
}
