<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setDate() / DateTimeImmutable::setDate() — VM (#12469, #29829). */
final class DateTimeSetDate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setDate');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::setDate() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setDate()'
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity excludes $this — php-src zim_DateTime_setDate (#30834).
        $this->requireExactUserArgCount($frame, "{$label}::setDate", 3);
        // Z_PARAM_LONG — caller strict_types → TypeError on null (#29829).
        // VmMath userArgIndex is 1-based (Argument #N).
        $year = VmMath::parseZParamLongBuiltinArgForFrame($frame, 1, "{$label}::setDate", 1, 'year');
        $month = VmMath::parseZParamLongBuiltinArgForFrame($frame, 2, "{$label}::setDate", 2, 'month');
        $day = VmMath::parseZParamLongBuiltinArgForFrame($frame, 3, "{$label}::setDate", 3, 'day');
        if (null === $frame->returnVar) {
            return;
        }
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $frame->returnVar->object(DateTimeSupport::withDate($receiver, $year, $month, $day));

            return;
        }
        DateTimeSupport::setDate($receiver, $year, $month, $day);
        $frame->returnVar->object($receiver);
    }
}
