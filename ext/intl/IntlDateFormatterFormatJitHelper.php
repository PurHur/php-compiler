<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * IntlDateFormatter create/format prop names for JIT/AOT (#27361).
 *
 * Object props carry the resolved PHP date() format + timezone from create so thin AOT
 * does not depend on VmIntlDateFormatter::$state (object ids differ across runtimes).
 *
 * Format lowering uses {@see \PHPCompiler\JIT\Builtin\DateTimeFormatRuntime} (NestedJIT
 * {@see \PHPCompiler\ext\standard\DateTimeFormatJitHelper::formatStateArgv}).
 *
 * php-src: ext/intl/dateformat/dateformat_format.c — PHP_FUNCTION(datefmt_format)
 */
final class IntlDateFormatterFormatJitHelper
{
    public const PROP_PHP_FORMAT = '__datefmt_php_format';

    public const PROP_TIMEZONE = '__datefmt_timezone';

    public const PROP_LOCALE = '__datefmt_locale';

    public const PROP_DATE_TYPE = '__datefmt_date_type';

    public const PROP_TIME_TYPE = '__datefmt_time_type';
}
