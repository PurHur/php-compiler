<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::sub() / DateTimeImmutable::sub() — VM (#10946, php-src zim_DateTime_sub). */
final class DateTimeSub extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('sub');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::sub() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::sub()',
            $frame->vmContext
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity excludes $this — php-src zim_DateTime_sub (#30834).
        $this->requireExactUserArgCount($frame, "{$label}::sub", 1);
        $interval = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[1],
            "{$label}::sub()",
            1,
            'interval'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $frame->returnVar->object(DateTimeSupport::withSubInterval($receiver, $interval));

            return;
        }
        DateTimeSupport::subInterval($receiver, $interval);
        $frame->returnVar->object($receiver);
    }
}
