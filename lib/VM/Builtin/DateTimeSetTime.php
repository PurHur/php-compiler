<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setTime() / DateTimeImmutable::setTime() — VM (#12469). */
final class DateTimeSetTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setTime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException('DateTime::setTime() expects two to four arguments');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setTime()'
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        $hour = VmMath::parseIntBuiltinArg($frame->calledArgs[1], "{$label}::setTime", 0, 'hour');
        $minute = VmMath::parseIntBuiltinArg($frame->calledArgs[2], "{$label}::setTime", 1, 'minute');
        $second = ($argc >= 4)
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[3], "{$label}::setTime", 2, 'second')
            : 0;
        $microsecond = (5 === $argc)
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[4], "{$label}::setTime", 3, 'microsecond')
            : 0;
        if (null === $frame->returnVar) {
            return;
        }
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $frame->returnVar->object(
                DateTimeSupport::withTime($receiver, $hour, $minute, $second, $microsecond)
            );

            return;
        }
        DateTimeSupport::setTime($receiver, $hour, $minute, $second, $microsecond);
        $frame->returnVar->object($receiver);
    }
}
