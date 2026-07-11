<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Bridge malformed DateInterval specs from VM date builtins into catchable VM exceptions (#7129, #15382).
 *
 * php-src: ext/date/php_date.c — DateMalformedIntervalException (PHP 8.3+).
 */
final class NativeDateMalformedIntervalException extends \Exception
{
}
