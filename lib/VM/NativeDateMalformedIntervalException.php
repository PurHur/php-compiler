<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Internal signal for malformed DateInterval specs; materializes as
 * DateMalformedIntervalStringException under the date hierarchy profile.
 *
 * php-src: ext/date/php_date.c — DateMalformedIntervalStringException (PHP 8.3+).
 */
final class NativeDateMalformedIntervalException extends \Exception
{
}
