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
        self::assertFalse(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'Soap\\Url')
        );
        self::assertFalse(
            ext\standard\VmReflection::classExists($runtime->vmContext, 'Soap\\Sdl')
        );
        self::assertFalse(SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes());
    }

    public function testSoapUrlSdlOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(SoapExtensionPolicy::advertisesExtension());
            self::assertTrue(SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes());

            $runtime = new Runtime();
            self::assertTrue(
                ext\standard\VmReflection::classExists($runtime->vmContext, 'Soap\\Url')
            );
            self::assertTrue(
                ext\standard\VmReflection::classExists($runtime->vmContext, 'Soap\\Sdl')
            );
            $url = $runtime->vmContext->classes['soap\\url'] ?? null;
            $sdl = $runtime->vmContext->classes['soap\\sdl'] ?? null;
            self::assertNotNull($url);
            self::assertNotNull($sdl);
            self::assertTrue($url->isFinal);
            self::assertTrue($url->isInternal);
            self::assertTrue($sdl->isFinal);
            self::assertTrue($sdl->isInternal);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
