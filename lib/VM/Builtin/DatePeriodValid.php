<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::valid() — php-src date_period_valid (#14228). */
final class DatePeriodValid extends DatePeriodIteratorMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(DatePeriodSupport::iteratorValid(self::receiver($frame)));
    }
}
