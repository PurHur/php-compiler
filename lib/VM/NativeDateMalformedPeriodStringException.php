<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Bridge malformed DatePeriod ISO8601 specs from VM date builtins into catchable VM exceptions (#7296).
 *
 * php-src: ext/date/php_date.c — DateMalformedPeriodStringException (PHP 8.4+).
 */
final class NativeDateMalformedPeriodStringException extends \Exception
{
}
