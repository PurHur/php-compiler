<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\soap\SoapExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group soap_extension_policy */
final class SoapExtensionPolicyTest extends TestCase
{
    public function testWithholdsOnReferenceWithoutHostSoap(): void
    {
        if (\extension_loaded('soap') || \PHPCompiler\CompilerVersion::supportsSoap()) {
            $this->markTestSkipped('soap advertised on this host/profile');
        }

        self::assertFalse(SoapExtensionPolicy::advertisesExtension());
        self::assertFalse(SoapExtensionPolicy::advertisesExceptionClass());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('soap')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'is_soap_fault')
        );
        self::assertFalse(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'SoapClient')
        );
        self::assertFalse(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'SoapServer')
        );
        self::assertFalse(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'SoapFault')
        );
    }
}
