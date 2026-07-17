<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/**
 * ext/soap advertisement — php-src ext/soap/soap.c (#20124 / #3724 / #20267).
 *
 * Soap module is always loaded (VM-only surface); SoapFault follows.
 * Tracks SOAP_GLOBAL(use_soap_error_handler) for soap_error_handler (#20267).
 */
final class SoapExtensionPolicy
{
    /** php-src SOAP_GLOBAL(use_soap_error_handler) — MINIT/RINIT default false. */
    private static bool $useSoapErrorHandler = false;

    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function advertisesExceptionClass(): bool
    {
        return self::advertisesExtension();
    }

    public static function useSoapErrorHandler(): bool
    {
        return self::$useSoapErrorHandler;
    }

    public static function setUseSoapErrorHandler(bool $enable): void
    {
        self::$useSoapErrorHandler = $enable;
    }

    /**
     * When true, PHP errors during SoapClient/SoapServer ops become SoapFault
     * (php-src soap_error_handler). Call sites that emit warnings should consult this.
     */
    public static function shouldConvertErrorsToSoapFault(): bool
    {
        return self::$useSoapErrorHandler;
    }
}
