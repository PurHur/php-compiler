<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

/**
 * ext/dba advertisement (php-src ext/dba/dba.c; #4422).
 *
 * Phase 1 always advertises the flatfile handler surface (PHP-in-PHP; no libdb).
 */
final class DbaExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }
}
