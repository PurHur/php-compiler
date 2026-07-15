<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

/**
 * ext/phar advertisement — php-src ext/phar/phar.c (#3436).
 */
final class PharExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }
}
