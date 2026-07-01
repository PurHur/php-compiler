<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DatePeriodSupport;

/** DatePeriod::key() — php-src date_period_key (#14228). */
final class DatePeriodKey extends DatePeriodIteratorMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(DatePeriodSupport::iteratorKey(self::receiver($frame)));
    }
}
