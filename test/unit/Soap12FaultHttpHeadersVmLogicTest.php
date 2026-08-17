<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\SoapServerState;
use PHPCompiler\ext\soap\VmSoapServer;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;
use PHPUnit\Framework\TestCase;

/**
 * SoapServer handle() transport headers without host php-soap (#31957).
 *
 * @covers issue #31957
 */
final class Soap12FaultHttpHeadersVmLogicTest extends TestCase
{
    protected function setUp(): void
    {
        ResponseContext::reset();
        ResponseContext::enableHeaderQueue();
        class_exists(VmSoapServer::class);
    }

    protected function tearDown(): void
    {
        ResponseContext::reset();
    }

    public function testEmitFaultHeadersSoap12(): void
    {
        $state = new SoapServerState();
        $state->soapVersion = SoapConstants::SOAP_1_2;
        $ctx = new Context(new Runtime());
        $server = new HashTable();
        $ua = new Variable();
        $ua->string('Mozilla/5.0');
        $server->add('HTTP_USER_AGENT', $ua);
        $ctx->ensureSuperglobal('_SERVER');
        $ctx->getSuperglobal('_SERVER')->array($server);

        $emit = new \ReflectionMethod(VmSoapServer::class, 'emitHandleTransportHeaders');
        $emit->setAccessible(true);
        $emit->invoke(null, $state, $ctx, true);

        $headers = ResponseContext::listHeaders();
        $joined = implode("\n", $headers);
        $this->assertStringContainsString('500 Internal Server Error', $joined);
        $this->assertStringContainsString('application/soap+xml', $joined);
        $this->assertSame(500, ResponseContext::getStatus());
    }

    public function testEmitSuccessHeadersSoap12(): void
    {
        $state = new SoapServerState();
        $state->soapVersion = SoapConstants::SOAP_1_2;
        $ctx = new Context(new Runtime());

        $emit = new \ReflectionMethod(VmSoapServer::class, 'emitHandleTransportHeaders');
        $emit->setAccessible(true);
        $emit->invoke(null, $state, $ctx, false);

        $headers = ResponseContext::listHeaders();
        $this->assertSame(
            ['Content-Type: application/soap+xml; charset=utf-8'],
            $headers
        );
        $this->assertSame(200, ResponseContext::getEffectiveStatus());
    }

    public function testFlashUserAgentSkipsHttp500(): void
    {
        $state = new SoapServerState();
        $state->soapVersion = SoapConstants::SOAP_1_1;
        $ctx = new Context(new Runtime());
        $server = new HashTable();
        $ua = new Variable();
        $ua->string('Shockwave Flash');
        $server->add('HTTP_USER_AGENT', $ua);
        $ctx->ensureSuperglobal('_SERVER');
        $ctx->getSuperglobal('_SERVER')->array($server);

        $emit = new \ReflectionMethod(VmSoapServer::class, 'emitHandleTransportHeaders');
        $emit->setAccessible(true);
        $emit->invoke(null, $state, $ctx, true);

        $headers = ResponseContext::listHeaders();
        $joined = implode("\n", $headers);
        $this->assertStringNotContainsString('500 Internal Server Error', $joined);
        $this->assertStringContainsString('text/xml; charset=utf-8', $joined);
    }
}
