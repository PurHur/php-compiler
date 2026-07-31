<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * soap extension module entry (php-src ext/soap/soap.c; #20037 / #20124 / #3724).
 *
 * SoapClient + SoapFault + is_soap_fault (VM+JIT #26167) + use_soap_error_handler. No new runtime C.
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'soap';
    }

    public function getExtensionVersion(): string
    {
        return '1.0.0';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!SoapExtensionPolicy::advertisesExtension()) {
            return;
        }
        require_once __DIR__.'/bootstrap_soapfault.php';
        // php-src SOAP_RINIT: use_soap_error_handler = 0
        SoapExtensionPolicy::setUseSoapErrorHandler(false);
        foreach (SoapConstants::registeredConstants() as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            if (\is_string($value)) {
                $var->string($value);
            } else {
                $var->int($value);
            }
            $runtime->vmContext->defineConstant($name, $var);
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!SoapExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new is_soap_fault(),
            new use_soap_error_handler(),
        ];
    }
}
