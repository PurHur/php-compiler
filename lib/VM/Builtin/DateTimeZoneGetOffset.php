<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTimeZone::getOffset(DateTimeInterface $datetime) — VM (#7131, php-src ext/date/php_datetime.c). */
final class DateTimeZoneGetOffset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getOffset');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTimeZone::getOffset() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'DateTimeZone::getOffset()'
        );
        // User arity excludes $this — php-src zim_DateTimeZone_getOffset (#30834).
        $this->requireExactUserArgCount($frame, 'DateTimeZone::getOffset', 1);
        $datetime = DateTimeSupport::requireDateTimeInterface(
            $frame->calledArgs[1],
            'DateTimeZone::getOffset(): Argument #1 ($datetime)',
            $frame->vmContext
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(DateTimeSupport::timezoneOffset($receiver, $datetime));
    }
}
