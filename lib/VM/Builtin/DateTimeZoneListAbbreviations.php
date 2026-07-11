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
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmDateTimeNative::timezoneAbbreviationsListVariable());
    }
}
