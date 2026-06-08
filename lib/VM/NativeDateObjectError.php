<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Bridge DateObjectError from VM date builtins into catchable VM errors (#7276).
 *
 * php-src: ext/date/php_date.c — DateObjectError (PHP 8.3+); uninitialized date objects.
 */
final class NativeDateObjectError extends \Error
{
}
