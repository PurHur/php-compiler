<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * calendar extension module entry (php-src ext/calendar/calendar.c; issue #7133).
 *
 * Julian-day algorithms land in #3742 / #6759.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach (CalendarConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new cal_days_in_month(),
            new cal_to_jd(),
            new gregoriantojd(),
            new jdtogregorian(),
            new jdtojulian(),
            new unixtojd(),
            new jdtounix(),
            new easter_date(),
        ];
    }
}
