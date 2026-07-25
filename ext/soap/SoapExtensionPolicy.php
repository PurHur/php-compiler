<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/**
 * ext/soap advertisement — php-src ext/soap/soap.c (#20124 / #3724 / #20267 / #22859).
 *
 * SoapClient / SoapServer / SoapFault stay in-tree (PHP-in-PHP) but are withheld from
 * extension_loaded() / class_exists() on the reference harness when host Zend has no
 * php-soap — same shape as yaml/brotli (#6275 / #17563). Enable via host ext/soap or
 * `PHP_COMPILER_PROFILE=8.4` ({@see CompilerVersion::supportsSoap()}).
 *
 * Tracks SOAP_GLOBAL(use_soap_error_handler) for soap_error_handler (#20267).
 */
final class SoapExtensionPolicy
{
    /** php-src SOAP_GLOBAL(use_soap_error_handler) — MINIT/RINIT default false. */
    private static bool $useSoapErrorHandler = false;

    /**
     * extension_loaded('soap') / CREDITS_MODULES — match Zend without phantom soap (#22859).
     */
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('soap')) {
            return true;
        }

        return \PHPCompiler\CompilerVersion::supportsSoap();
    }

    public static function advertisesExceptionClass(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise SoapClient / SoapServer / SoapFault. */
    public static function isSoapComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'soap_')
            || str_contains($testFileName, 'soapclient')
            || str_contains($testFileName, 'soapserver')
            || str_contains($testFileName, 'soapfault')
            || str_contains($testFileName, 'is_soap_fault')
            || str_contains($testFileName, 'use_soap_error_handler')
            || str_contains($testFileName, 'extension_loaded_soap');
    }

    /** Phantom-registration guards that assert soap is withheld (#22859). */
    public static function isSoapPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'soap_phantom')
            || str_contains($testFileName, 'extension_loaded_soap_phantom');
    }

    /** Run functional soap compliance when advertised, else phantom only (#22859). */
    public static function runsSoapCompliance(string $testFileName): bool
    {
        if (self::advertisesExtension()) {
            return !self::isSoapPhantomComplianceCase($testFileName);
        }

        return self::isSoapPhantomComplianceCase($testFileName);
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
