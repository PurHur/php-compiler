<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Bridge DateRangeError from VM date builtins into catchable VM errors (#7276).
 *
 * php-src: ext/date/php_date.c — DateRangeError (PHP 8.3+); epoch overflow on getTimestamp().
 */
final class NativeDateRangeError extends \Error
{
}
