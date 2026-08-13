<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setTime() / DateTimeImmutable::setTime() — VM (#12469, #29829). */
final class DateTimeSetTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setTime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('DateTime::setTime() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setTime()'
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity 2–4 — php-src zim_DateTime_setTime (#30834); ACE not LogicException.
        $this->requireUserArgCountRange($frame, "{$label}::setTime", 2, 4);
        // Z_PARAM_LONG — caller strict_types → TypeError on null (#29829).
        // VmMath userArgIndex is 1-based (Argument #N).
        $hour = VmMath::parseZParamLongBuiltinArgForFrame($frame, 1, "{$label}::setTime", 1, 'hour');
        $minute = VmMath::parseZParamLongBuiltinArgForFrame($frame, 2, "{$label}::setTime", 2, 'minute');
        $second = ($argc >= 4)
            ? VmMath::parseZParamLongBuiltinArgForFrame($frame, 3, "{$label}::setTime", 3, 'second')
            : 0;
        $microsecond = (5 === $argc)
            ? VmMath::parseZParamLongBuiltinArgForFrame($frame, 4, "{$label}::setTime", 4, 'microsecond')
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
