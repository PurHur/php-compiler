<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;

/**
 * date_default_timezone_get/set for compiled JIT/AOT modules (#9243, php-in-PHP).
 *
 * SSOT: {@see VmDate::defaultTimezoneGet()} / {@see VmDate::tryDefaultTimezoneSet()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_default_timezone_get/set)
 */
final class DefaultTimezoneJitHelper
{
    public static function defaultTimezoneGet(): string
    {
        return VmDate::defaultTimezoneGet();
    }

    public static function tryDefaultTimezoneSet(string $timezone): bool
    {
        return VmDate::tryDefaultTimezoneSet($timezone);
    }

    public static function emitInvalidTimezoneNotice(string $timezone): void
    {
        $message = "date_default_timezone_set(): Timezone ID '{$timezone}' is invalid";
        if (TriggerErrorJitHelper::recordTrigger(ErrorReporter::E_NOTICE, $message, '', 0)) {
            TriggerErrorJitHelper::stderrPrintCliError(ErrorReporter::E_NOTICE, $message, '', 0);
        }
    }
}
