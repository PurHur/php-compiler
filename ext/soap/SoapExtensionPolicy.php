<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/**
 * ext/soap advertisement — php-src ext/soap/soap.c (#20124 / #3724).
 *
 * Soap module is always loaded (VM-only surface); SoapFault follows.
 */
final class SoapExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function advertisesExceptionClass(): bool
    {
        return self::advertisesExtension();
    }
}
