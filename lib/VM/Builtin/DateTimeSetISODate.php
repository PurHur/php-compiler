<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setISODate() / DateTimeImmutable::setISODate() — VM (#19847). */
final class DateTimeSetISODate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setISODate');
    }

    public function execute(Frame $frame): void
    {
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setISODate()'
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        $this->requireUserArgCountRange($frame, "{$label}::setISODate", 2, 3);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_LONG — caller strict_types → TypeError on null (#29842).
        // Frame args include $this; user-visible Argument #1 ($year) …
        $year = VmMath::parseZParamLongBuiltinArgForFrame($frame, 1, "{$label}::setISODate", 1, 'year');
        $week = VmMath::parseZParamLongBuiltinArgForFrame($frame, 2, "{$label}::setISODate", 2, 'week');
        $dayOfWeek = (4 === $argc)
            ? VmMath::parseZParamLongBuiltinArgForFrame($frame, 3, "{$label}::setISODate", 3, 'dayOfWeek')
            : 1;
        if (null === $frame->returnVar) {
            return;
        }
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $frame->returnVar->object(
                DateTimeSupport::withISODate($receiver, $year, $week, $dayOfWeek)
            );

            return;
        }
        DateTimeSupport::setISODate($receiver, $year, $week, $dayOfWeek);
        $frame->returnVar->object($receiver);
    }
}
