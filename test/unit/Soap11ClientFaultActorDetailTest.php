<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapClient SOAP 1.1 faultactor/detail (#32048).
 *
 * @covers issue #32048
 */
final class Soap11ClientFaultActorDetailTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\class_exists(\SoapFault::class, false)) {
            require_once dirname(__DIR__, 2).'/ext/soap/bootstrap_soapfault.php';
        }
    }

    public function testSoap11FaultactorAndDetail(): void
    {
        $xml = '<?xml version="1.0"?>'
            .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<SOAP-ENV:Body><SOAP-ENV:Fault>'
            .'<faultcode>SOAP-ENV:Server</faultcode>'
            .'<faultstring>boom11</faultstring>'
            .'<faultactor>http://example.com/actor</faultactor>'
            .'<detail><item>x</item></detail>'
            .'</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';
        $e = $this->decodeFault($xml);
        $this->assertSame('SOAP-ENV:Server', $e->faultcode);
        $this->assertSame('boom11', $e->faultstring);
        $this->assertSame('http://example.com/actor', $e->faultactor);
        $this->assertInstanceOf(\stdClass::class, $e->detail);
        $this->assertSame('x', $e->detail->item);
    }

    public function testSoap12RoleNotCopiedToFaultactor(): void
    {
        $xml = '<?xml version="1.0"?>'
            .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
            .'<env:Body><env:Fault>'
            .'<env:Code><env:Value>env:Receiver</env:Value></env:Code>'
            .'<env:Reason><env:Text>boom</env:Text></env:Reason>'
            .'<env:Role>http://www.w3.org/2003/05/soap-envelope/role/next</env:Role>'
            .'</env:Fault></env:Body></env:Envelope>';
        $e = $this->decodeFault($xml);
        $this->assertSame('env:Receiver', $e->faultcode);
        $this->assertTrue(!isset($e->faultactor) || null === $e->faultactor || '' === $e->faultactor);
    }

    private function decodeFault(string $xml): \SoapFault
    {
        $rm = new \ReflectionMethod(VmSoapClient::class, 'decodeResponse');
        $rm->setAccessible(true);
        try {
            $rm->invoke(null, $xml, 'echo');
            $this->fail('expected SoapFault');
        } catch (\SoapFault $e) {
            return $e;
        }
    }
}
