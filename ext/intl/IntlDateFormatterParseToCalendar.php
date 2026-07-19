<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** IntlDateFormatter::parseToCalendar() — php-src parseToCalendar (#20729). */
final class IntlDateFormatterParseToCalendar extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parseToCalendar');
    }

    public function execute(Frame $frame): void
    {
        IntlDateFormatterParse::executeParse($frame, 'IntlDateFormatter::parseToCalendar', true);
    }
}
