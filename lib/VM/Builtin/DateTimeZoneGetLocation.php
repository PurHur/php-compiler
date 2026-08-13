<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTimeZone::getLocation() — VM (#7131, php-src ext/date/php_datetime.c). */
final class DateTimeZoneGetLocation extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLocation');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTimeZone::getLocation() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'DateTimeZone::getLocation()'
        );
        // User arity excludes $this — php-src zim_DateTimeZone_getLocation (#30834).
        $this->requireExactUserArgCount($frame, 'DateTimeZone::getLocation', 0);
        if (null === $frame->returnVar) {
            return;
        }
        DateTimeSupport::timezoneLocationInto($receiver, $frame->returnVar);
    }
}
