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

    public function testSoapClientHttpurlPropertyOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $entry = $ctx->classes['soapclient'] ?? null;
            self::assertNotNull($entry);
            $names = \array_map(static fn ($p) => $p->name, $entry->properties);
            self::assertContains('httpurl', $names);

            $object = new \PHPCompiler\VM\ObjectEntry($entry);
            \PHPCompiler\ext\soap\VmSoapClient::initObject(
                $object,
                null,
                ['location' => 'http://127.0.0.1/', 'uri' => 'http://test/'],
                $ctx
            );
            self::assertTrue($object->hasProperty('httpurl'));
            self::assertSame(
                \PHPCompiler\VM\Variable::TYPE_NULL,
                $object->getProperty('httpurl')->type
            );

            // Simulate successful HTTP attach (php-src php_http.c).
            $ref = new \ReflectionClass(\PHPCompiler\ext\soap\VmSoapClient::class);
            $m = $ref->getMethod('attachHttpUrl');
            $m->setAccessible(true);
            $m->invoke(null, $object, 'http://127.0.0.1/soap');
            $urlVar = $object->getProperty('httpurl');
            self::assertSame(\PHPCompiler\VM\Variable::TYPE_OBJECT, $urlVar->type);
            self::assertSame('Soap\\Url', $urlVar->toObject()->class->name);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSoapClientSdlPropertyOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $entry = $ctx->classes['soapclient'] ?? null;
            self::assertNotNull($entry);
            $names = \array_map(static fn ($p) => $p->name, $entry->properties);
            self::assertContains('sdl', $names);

            $dir = \sys_get_temp_dir().'/phpc_soap_sdl_u_'.\getmypid();
            @\mkdir($dir);
            $wsdl = $dir.'/s.wsdl';
            \file_put_contents($wsdl, '<?xml version="1.0"?>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/"
  xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
  xmlns:tns="http://test/" targetNamespace="http://test/" name="T">
  <message name="mIn"/><message name="mOut"/>
  <portType name="P"><operation name="ping"><input message="tns:mIn"/><output message="tns:mOut"/></operation></portType>
  <binding name="B" type="tns:P"><soap:binding style="rpc" transport="http://schemas.xmlsoap.org/soap/http"/>
    <operation name="ping"><soap:operation soapAction="ping"/><input/><output/></operation></binding>
  <service name="S"><port name="Port" binding="tns:B"><soap:address location="http://127.0.0.1/"/></port></service>
</definitions>');

            $object = new \PHPCompiler\VM\ObjectEntry($entry);
            \PHPCompiler\ext\soap\VmSoapClient::initObject($object, $wsdl, [], $ctx);
            self::assertTrue($object->hasProperty('sdl'));
            $sdlVar = $object->getProperty('sdl');
            self::assertSame(\PHPCompiler\VM\Variable::TYPE_OBJECT, $sdlVar->type);
            self::assertSame('Soap\\Sdl', $sdlVar->toObject()->class->name);

            @\unlink($wsdl);
            @\rmdir($dir);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
