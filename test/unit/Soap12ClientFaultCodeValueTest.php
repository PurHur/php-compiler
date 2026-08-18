<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapClient SOAP 1.2 Fault Code/Value only (#32045).
 *
 * @covers issue #32045
 */
final class Soap12ClientFaultCodeValueTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\class_exists(\SoapFault::class, false)) {
            require_once dirname(__DIR__, 2).'/ext/soap/bootstrap_soapfault.php';
        }
    }

    public function testSoap12CodeUsesValueNotConcatenatedSubcode(): void
    {
        $xml = '<?xml version="1.0"?>'
            .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
            .'<env:Body><env:Fault>'
            .'<env:Code><env:Value>env:Receiver</env:Value>'
            .'<env:Subcode><env:Value>app:Specific</env:Value></env:Subcode></env:Code>'
            .'<env:Reason><env:Text xml:lang="en">boom-en</env:Text></env:Reason>'
            .'</env:Fault></env:Body></env:Envelope>';
        $e = $this->decodeFault($xml);
        $this->assertSame('env:Receiver', $e->faultcode);
        $this->assertStringNotContainsString('Specific', (string) $e->faultcode);
    }

    public function testSoap12CodeWithoutSubcodeStillValue(): void
    {
        $xml = '<?xml version="1.0"?>'
            .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
            .'<env:Body><env:Fault>'
            .'<env:Code><env:Value>env:Sender</env:Value></env:Code>'
            .'<env:Reason><env:Text>nope</env:Text></env:Reason>'
            .'</env:Fault></env:Body></env:Envelope>';
        $e = $this->decodeFault($xml);
        $this->assertSame('env:Sender', $e->faultcode);
    }

    public function testSoap11FaultcodeUnchanged(): void
    {
        $xml = '<?xml version="1.0"?>'
            .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<SOAP-ENV:Body><SOAP-ENV:Fault>'
            .'<faultcode>SOAP-ENV:Server</faultcode>'
            .'<faultstring>boom11</faultstring>'
            .'</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';
        $e = $this->decodeFault($xml);
        $this->assertSame('SOAP-ENV:Server', $e->faultcode);
        $this->assertSame('boom11', $e->faultstring);
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
