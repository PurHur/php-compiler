<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::getTimezone() / DateTimeImmutable::getTimezone() — VM (#10946). */
final class DateTimeGetTimezone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimezone');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::getTimezone() called without $this');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTime::getTimezone() requires VM context');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::getTimezone()',
            $frame->vmContext
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity excludes $this — php-src zim_DateTime_getTimezone (#30834).
        $this->requireExactUserArgCount($frame, "{$label}::getTimezone", 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(
            DateTimeSupport::getTimezoneObject($receiver, $frame->vmContext)
        );
    }
}
