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
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('DateTime::setISODate() expects two to three arguments');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setISODate()'
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        $year = VmMath::parseIntBuiltinArg($frame->calledArgs[1], "{$label}::setISODate", 0, 'year');
        $week = VmMath::parseIntBuiltinArg($frame->calledArgs[2], "{$label}::setISODate", 1, 'week');
        $dayOfWeek = (4 === $argc)
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[3], "{$label}::setISODate", 2, 'dayOfWeek')
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
