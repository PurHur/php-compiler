<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::current() — php-src date_period_current (#14228). */
final class DatePeriodCurrent extends DatePeriodIteratorMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $current = DatePeriodSupport::iteratorCurrent(self::receiver($frame));
        if (null === $current) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->object($current);
        }
    }
}
