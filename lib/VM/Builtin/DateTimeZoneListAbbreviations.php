<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\Frame;

/** DateTimeZone::listAbbreviations() — VM (#11874, php-src ext/date/php_date.c). */
final class DateTimeZoneListAbbreviations extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('listAbbreviations');
    }

    public function execute(Frame $frame): void
    {
        // Static — calledArgs has no $this (php-src zim_DateTimeZone_listAbbreviations, #30898).
        $this->requireExactArgCount($frame, 'DateTimeZone::listAbbreviations', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmDateTimeNative::timezoneAbbreviationsListVariable());
    }
}
