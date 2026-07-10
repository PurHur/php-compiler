<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Bridge illegal date mutations from VM date builtins into catchable VM exceptions (#6048).
 *
 * php-src: ext/date/php_date.c — DateInvalidOperationException (PHP 8.3+).
 */
final class NativeDateInvalidOperationException extends \Exception
{
}
