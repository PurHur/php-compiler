<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * soap extension module entry (php-src ext/soap/soap.c; #20037 / #3724).
 *
 * VM-only v1: SoapClient + local WSDL/file transport. No new runtime C.
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
        require_once __DIR__.'/bootstrap_soapfault.php';
        parent::init($runtime);
        foreach (SoapConstants::registeredConstants() as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        BuiltinClasses::register($runtime->vmContext);
    }
}
