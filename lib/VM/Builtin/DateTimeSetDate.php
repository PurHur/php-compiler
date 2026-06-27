<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setDate() / DateTimeImmutable::setDate() — VM (#12469). */
final class DateTimeSetDate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setDate');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 4) {
            throw new \LogicException('DateTime::setDate() expects exactly 3 arguments');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setDate()'
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        $year = VmMath::parseIntBuiltinArg($frame->calledArgs[1], "{$label}::setDate", 0, 'year');
        $month = VmMath::parseIntBuiltinArg($frame->calledArgs[2], "{$label}::setDate", 1, 'month');
        $day = VmMath::parseIntBuiltinArg($frame->calledArgs[3], "{$label}::setDate", 2, 'day');
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
