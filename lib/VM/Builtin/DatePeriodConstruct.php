<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * DatePeriod::__construct — VM (#14144, #14228, #21939).
 *
 * Overloads (php-src ext/date/php_date.c date_period_construct):
 * - (string $isostr [, int $options]) — ISO-8601 (#21939)
 * - (DateTimeInterface, DateInterval, int|DateTimeInterface [, int $options])
 */
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
        $userArgs = $argc - 1;
        if ($userArgs < 1 || $userArgs > 4) {
            throw new \TypeError(DatePeriodSupport::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DatePeriod::__construct() requires VM context in this compiler build');
        }
        DatePeriodSupport::assertConstructorOverload($frame, $argc, $frame->vmContext);

        if ($userArgs <= 2) {
            $spec = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'DatePeriod::__construct',
                1,
                'isostr'
            );
            $options = 0;
            if (2 === $userArgs) {
                $options = InternalStrictArg::requireBuiltinTypedInt(
                    $frame,
                    2,
                    'DatePeriod::__construct',
                    'options'
                )->toInt();
            }
            DatePeriodSupport::initFromISO8601String(
                $receiver,
                $frame->vmContext,
                $spec,
                $options,
                'DatePeriod::__construct()'
            );

            return;
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
        $options = 0;
        if ($argc >= 5) {
            $options = InternalStrictArg::requireBuiltinTypedInt(
                $frame,
                4,
                'DatePeriod::__construct',
                'options'
            )->toInt();
        }
        $third = $frame->calledArgs[3]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $third->type) {
            $end = DateTimeSupport::requireDateTimeInterface(
                $frame->calledArgs[3],
                'DatePeriod::__construct()',
                $frame->vmContext,
                3,
                'end'
            );
            DateTimeSupport::requireInitializedDateTimeLike($end, $end->class->name);
            DatePeriodSupport::initFromEndDate($receiver, $start, $interval, $end, $options, $frame->vmContext);

            return;
        }
        // php-src stub: int|DateTimeInterface $end — Z_PARAM_LONG soft-null DEP+0 (#31527).
        $recurrences = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            3,
            'DatePeriod::__construct',
            3,
            'end'
        );
        DatePeriodSupport::initFromRecurrenceCount($receiver, $start, $interval, $recurrences, $options, $frame->vmContext);
    }
}
