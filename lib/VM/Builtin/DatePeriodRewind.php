<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::rewind() — php-src date_period_rewind (#14228). */
final class DatePeriodRewind extends DatePeriodIteratorMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        DatePeriodSupport::iteratorRewind(self::receiver($frame));
    }
}
