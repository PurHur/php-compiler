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
        if (\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap present');
        }

        self::assertFalse(SoapExtensionPolicy::advertisesExtension());
        self::assertFalse(SoapExtensionPolicy::advertisesExceptionClass());
        self::assertFalse(CompilerVersion::supportsSoap());

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

    /** PROFILE=8.4 must not invent soap when host Zend lacks php-soap (#25165). */
    public function testWithholdsOnForwardProfile84WithoutHostSoap(): void
    {
        if (\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap present');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertFalse(CompilerVersion::supportsSoap());
            self::assertFalse(SoapExtensionPolicy::advertisesExtension());
            self::assertFalse(SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes());

            $runtime = new Runtime();
            self::assertFalse(
                ext\standard\ModuleRegistry::extensionLoaded('soap')
            );
            self::assertFalse(
                ext\standard\VmReflection::classExists($runtime->vmContext, 'SoapClient')
            );
            self::assertFalse(
                ext\standard\VmReflection::classExists($runtime->vmContext, 'Soap\\Url')
            );
            self::assertFalse(
                ext\standard\VmReflection::classExists($runtime->vmContext, 'Soap\\Sdl')
            );
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSoapUrlSdlWhenHostSoapAndForwardProfile84(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required for Soap\\Url / Soap\\Sdl surface');
        }

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
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required');
        }

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
            $m->invoke(null, $object, 'http://127.0.0.1:8080/soap?x=1');
            $urlVar = $object->getProperty('httpurl');
            self::assertSame(\PHPCompiler\VM\Variable::TYPE_OBJECT, $urlVar->type);
            self::assertSame('Soap\\Url', $urlVar->toObject()->class->name);
            $payload = \PHPCompiler\ext\soap\VmSoapOpaque::urlPayload($urlVar->toObject());
            self::assertNotNull($payload);
            self::assertSame('http', $payload->scheme);
            self::assertSame('127.0.0.1', $payload->host);
            self::assertSame(8080, $payload->port);
            self::assertSame('/soap', $payload->path);
            self::assertSame('x=1', $payload->query);

            $m->invoke(null, $object, 'https://example.com/api');
            $payload2 = \PHPCompiler\ext\soap\VmSoapOpaque::urlPayload(
                $object->getProperty('httpurl')->toObject()
            );
            self::assertNotNull($payload2);
            self::assertSame('https', $payload2->scheme);
            self::assertSame('example.com', $payload2->host);
            self::assertSame(443, $payload2->port);
            self::assertSame('/api', $payload2->path);
            self::assertFalse($payload->matchesHost($payload2));
            $same = \PHPCompiler\ext\soap\SoapUrlPayload::fromLocation('https://example.com/other');
            self::assertNotNull($same);
            self::assertTrue($payload2->matchesHost($same));
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
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required');
        }

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

            $payload = \PHPCompiler\ext\soap\VmSoapOpaque::sdlPayload($sdlVar->toObject());
            self::assertNotNull($payload);
            self::assertSame($wsdl, $payload->wsdl);
            self::assertContains('ping', $payload->functions);
            self::assertNotContains('void ping()', $payload->functions);
            self::assertSame(['void ping()'], \PHPCompiler\ext\soap\VmSoapClient::getFunctions($object));
            self::assertSame('http://127.0.0.1/', $payload->location);

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

    public function testSoapClientCoreOptionProperties(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $entry = $ctx->classes['soapclient'] ?? null;
            self::assertNotNull($entry);
            $names = \array_map(static fn ($p) => $p->name, $entry->properties);
            foreach (['uri', 'style', 'use', 'location', 'trace', 'compression'] as $prop) {
                self::assertContains($prop, $names);
            }

            $object = new \PHPCompiler\VM\ObjectEntry($entry);
            \PHPCompiler\ext\soap\VmSoapClient::initObject(
                $object,
                null,
                [
                    'location' => 'http://127.0.0.1/',
                    'uri' => 'http://test/',
                    'trace' => true,
                    'style' => \PHPCompiler\ext\soap\SoapConstants::SOAP_RPC,
                    'use' => \PHPCompiler\ext\soap\SoapConstants::SOAP_ENCODED,
                    'compression' => \PHPCompiler\ext\soap\SoapConstants::SOAP_COMPRESSION_ACCEPT,
                ],
                $ctx
            );
            self::assertSame('http://test/', $object->getProperty('uri')->toString());
            self::assertSame('http://127.0.0.1/', $object->getProperty('location')->toString());
            self::assertSame(\PHPCompiler\ext\soap\SoapConstants::SOAP_RPC, $object->getProperty('style')->toInt());
            self::assertSame(\PHPCompiler\ext\soap\SoapConstants::SOAP_ENCODED, $object->getProperty('use')->toInt());
            self::assertTrue($object->getProperty('trace')->toBool());
            self::assertSame(
                \PHPCompiler\ext\soap\SoapConstants::SOAP_COMPRESSION_ACCEPT,
                $object->getProperty('compression')->toInt()
            );
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSoapClientTraceFaultProperties(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $entry = $ctx->classes['soapclient'] ?? null;
            self::assertNotNull($entry);
            $names = \array_map(static fn ($p) => $p->name, $entry->properties);
            foreach ([
                '__last_request',
                '__last_response',
                '__last_request_headers',
                '__last_response_headers',
                '__default_headers',
                '__soap_fault',
            ] as $prop) {
                self::assertContains($prop, $names);
            }

            $object = new \PHPCompiler\VM\ObjectEntry($entry);
            \PHPCompiler\ext\soap\VmSoapClient::initObject(
                $object,
                null,
                [
                    'location' => 'http://127.0.0.1/',
                    'uri' => 'http://test/',
                    'trace' => true,
                ],
                $ctx
            );
            self::assertSame(
                \PHPCompiler\VM\Variable::TYPE_NULL,
                $object->getProperty('__last_request')->type
            );
            self::assertSame(
                \PHPCompiler\VM\Variable::TYPE_NULL,
                $object->getProperty('__default_headers')->type
            );
            self::assertSame(
                \PHPCompiler\VM\Variable::TYPE_NULL,
                $object->getProperty('__soap_fault')->type
            );

            $hdrEntry = $ctx->classes['soapheader'] ?? null;
            self::assertNotNull($hdrEntry);
            $hdr = new \PHPCompiler\VM\ObjectEntry($hdrEntry);
            $hdr->constructed = true;
            \PHPCompiler\ext\soap\VmSoapClient::setSoapHeaders($object, [$hdr]);
            $def = $object->getProperty('__default_headers');
            self::assertSame(\PHPCompiler\VM\Variable::TYPE_ARRAY, $def->type);
            self::assertCount(1, \iterator_to_array($def->toArray()->iterateKeyed(false)));

            \PHPCompiler\ext\soap\VmSoapClient::setSoapHeaders($object, []);
            self::assertSame(
                \PHPCompiler\VM\Variable::TYPE_NULL,
                $object->getProperty('__default_headers')->type
            );
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
