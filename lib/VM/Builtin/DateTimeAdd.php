<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::add() / DateTimeImmutable::add() — VM (#10946, php-src zim_DateTime_add). */
final class DateTimeAdd extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('add');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::add() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::add()',
            $frame->vmContext
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity excludes $this — php-src zim_DateTime_add (#30834).
        $this->requireExactUserArgCount($frame, "{$label}::add", 1);
        $interval = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[1],
            "{$label}::add()",
            1,
            'interval'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $frame->returnVar->object(DateTimeSupport::withAddInterval($receiver, $interval));

            return;
        }
        DateTimeSupport::addInterval($receiver, $interval);
        $frame->returnVar->object($receiver);
    }
}
