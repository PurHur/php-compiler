<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setTimezone(DateTimeZone $timezone) — VM (#3072). */
final class DateTimeSetTimezone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setTimezone');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DateTime::setTimezone() expects exactly 1 argument');
        }
        $receiver = DateTimeSupport::requireDateTime($frame->calledArgs[0], 'DateTime::setTimezone()');
        $timezone = DateTimeSupport::requireDateTimeZone($frame->calledArgs[1], 'DateTime::setTimezone() timezone');
        DateTimeSupport::setTimezone($receiver, $timezone);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($frame->calledArgs[0]);
        }
    }
}
