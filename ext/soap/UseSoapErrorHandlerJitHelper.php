<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/**
 * use_soap_error_handler() for compiled JIT/AOT modules (#26168, php-in-PHP).
 *
 * SSOT: {@see SoapExtensionPolicy}
 * php-src: ext/soap/soap.c — PHP_FUNCTION(use_soap_error_handler)
 */
final class UseSoapErrorHandlerJitHelper
{
    /**
     * Set SOAP error handler flag; return previous as 0|1.
     */
    public static function toggle(int $enable): int
    {
        $previous = SoapExtensionPolicy::useSoapErrorHandler();
        SoapExtensionPolicy::setUseSoapErrorHandler(0 !== $enable);

        return $previous ? 1 : 0;
    }
}
