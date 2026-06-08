<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setMicrosecond() / DateTimeImmutable::setMicrosecond() — VM (#7082). */
final class DateTimeSetMicrosecond extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setMicrosecond');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \LogicException('DateTime::setMicrosecond() expects exactly 1 argument');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setMicrosecond()'
        );
        $microsecond = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'DateTime::setMicrosecond',
            0,
            'microsecond'
        );
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $updated = DateTimeSupport::withMicrosecond($receiver, $microsecond);
            if (null !== $frame->returnVar) {
                $frame->returnVar->object($updated);
            }

            return;
        }
        DateTimeSupport::setMicrosecond($receiver, $microsecond);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($receiver);
        }
    }
}
