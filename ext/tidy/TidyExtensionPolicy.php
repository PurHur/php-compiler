<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

/**
 * ext/tidy surface advertisement (php-src ext/tidy/tidy.c; #21464).
 *
 * Always advertise the in-tree module so function_exists('tidy_parse_string') is true.
 * Runtime work delegates to host Zend ext/tidy when {@see VmTidy::hostAvailable()}.
 */
final class TidyExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }
}
