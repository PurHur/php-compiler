<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\InternalStrictArg;

/** DatePeriod::__construct(DateTimeInterface, DateInterval, int) — VM (#14144, ext/date/php_date.c). */
final class DatePeriodConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('DatePeriod::__construct() called without $this');
        }
        $receiver = DatePeriodSupport::requireDatePeriod($frame->calledArgs[0], 'DatePeriod::__construct()');
        if ($argc < 4) {
            throw new \ArgumentCountError(
                'DatePeriod::__construct() expects at least 3 arguments, '.\max(0, $argc - 1).' given'
            );
        }
        if ($argc > 5) {
            throw new \ArgumentCountError(
                'DatePeriod::__construct() expects at most 4 arguments, '.\max(0, $argc - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DatePeriod::__construct() requires VM context in this compiler build');
        }
        $start = DateTimeSupport::requireDateTimeInterface(
            $frame->calledArgs[1],
            'DatePeriod::__construct()',
            $frame->vmContext,
            1,
            'start'
        );
        DateTimeSupport::requireInitializedDateTimeLike($start, $start->class->name);
        $interval = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[2],
            'DatePeriod::__construct()',
            2,
            'interval'
        );
        $recurrences = InternalStrictArg::requireBuiltinTypedInt(
            $frame,
            3,
            'DatePeriod::__construct',
            'recurrences'
        )->toInt();
        DatePeriodSupport::initFromRecurrenceCount($receiver, $start, $interval, $recurrences);
    }
}
