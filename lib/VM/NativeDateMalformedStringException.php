<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Bridge malformed DateTime strings from VM date builtins into catchable VM exceptions (#7113).
 *
 * php-src: ext/date/php_date.c — DateMalformedStringException (PHP 8.3+); PHP 8.2 throws Exception.
 * Host PHP 8.2 has no native DateMalformedStringException; VM materializes Exception until #6048 lands.
 */
final class NativeDateMalformedStringException extends \Exception
{
}
