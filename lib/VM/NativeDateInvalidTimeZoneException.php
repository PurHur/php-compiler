<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Bridge invalid DateTimeZone ids from VM date builtins into catchable VM exceptions (#7279).
 *
 * php-src: ext/date/php_datetimezone.c — DateInvalidTimeZoneException (PHP 8.3+).
 * Host PHP 8.2 has no native DateInvalidTimeZoneException; VM materializes the userland class.
 */
final class NativeDateInvalidTimeZoneException extends \Exception
{
}
