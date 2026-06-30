<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTimeZone::getTransitions() — VM (#11211, php-src zim_DateTimeZone_getTransitions). */
final class DateTimeZoneGetTransitions extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTransitions');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTimeZone::getTransitions() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'DateTimeZone::getTransitions()'
        );
        $argc = \count($frame->calledArgs) - 1;
        if ($argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('DateTimeZone::getTransitions() expects at most 2 arguments, %d given', $argc)
            );
        }
        $begin = \PHP_INT_MIN;
        $end = \PHP_INT_MAX;
        if ($argc >= 1) {
            $begin = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'DateTimeZone::getTransitions',
                1,
                'timestamp_begin'
            );
        }
        if ($argc >= 2) {
            $end = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'DateTimeZone::getTransitions',
                2,
                'timestamp_end'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $transitions = VmDateTimeNative::timezoneTransitions(
            DateTimeSupport::timezoneName($receiver),
            $begin,
            $end
        );
        DateTimeSupport::timezoneTransitionsInto($transitions, $frame->returnVar);
    }
}
