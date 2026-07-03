<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Bridge malformed DatePeriod specs from VM date builtins into catchable VM exceptions (#7129, #15382).
 *
 * php-src: ext/date/php_date.c — DateMalformedPeriodException (PHP 8.3+).
 */
final class NativeDateMalformedPeriodException extends \Exception
{
}
