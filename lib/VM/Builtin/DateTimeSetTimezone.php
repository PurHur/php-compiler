<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setTimezone() / DateTimeImmutable::setTimezone() — VM (#3072, #22824). */
final class DateTimeSetTimezone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setTimezone');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::setTimezone() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setTimezone()',
            $frame->vmContext
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity excludes $this — php-src zim_DateTime_setTimezone (#30834).
        $this->requireExactUserArgCount($frame, "{$label}::setTimezone", 1);
        // Zend zim_DateTime_setTimezone — Argument #1 ($timezone) (#29869).
        $timezone = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[1],
            "{$label}::setTimezone()",
            1,
            'timezone'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $frame->returnVar->object(DateTimeSupport::withTimezone($receiver, $timezone));

            return;
        }
        DateTimeSupport::setTimezone($receiver, $timezone);
        $frame->returnVar->object($receiver);
    }
}
