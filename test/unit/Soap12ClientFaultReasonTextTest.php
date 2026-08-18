<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapClient SOAP 1.2 Fault first Reason/Text only (#32046).
 *
 * @covers issue #32046
 */
final class Soap12ClientFaultReasonTextTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\class_exists(\SoapFault::class, false)) {
            require_once dirname(__DIR__, 2).'/ext/soap/bootstrap_soapfault.php';
        }
    }

    public function testSoap12ReasonUsesFirstTextNotConcatenated(): void
    {
        $xml = '<?xml version="1.0"?>'
            .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
            .'<env:Body><env:Fault>'
            .'<env:Code><env:Value>env:Receiver</env:Value></env:Code>'
            .'<env:Reason><env:Text xml:lang="en">boom-en</env:Text>'
            .'<env:Text xml:lang="fr">boom-fr</env:Text></env:Reason>'
            .'</env:Fault></env:Body></env:Envelope>';
        $e = $this->decodeFault($xml);
        $this->assertSame('boom-en', $e->faultstring);
        $this->assertStringNotContainsString('boom-fr', $e->faultstring);
        $this->assertSame('env:Receiver', $e->faultcode);
    }

    public function testSoap12SingleReasonTextUnchanged(): void
    {
        $xml = '<?xml version="1.0"?>'
            .'<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">'
            .'<env:Body><env:Fault>'
            .'<env:Code><env:Value>env:Sender</env:Value></env:Code>'
            .'<env:Reason><env:Text>nope</env:Text></env:Reason>'
            .'</env:Fault></env:Body></env:Envelope>';
        $e = $this->decodeFault($xml);
        $this->assertSame('nope', $e->faultstring);
    }

    public function testSoap11FaultstringUnchanged(): void
    {
        $xml = '<?xml version="1.0"?>'
            .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<SOAP-ENV:Body><SOAP-ENV:Fault>'
            .'<faultcode>SOAP-ENV:Server</faultcode>'
            .'<faultstring>boom11</faultstring>'
            .'</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';
        $e = $this->decodeFault($xml);
        $this->assertSame('boom11', $e->faultstring);
        $this->assertSame('SOAP-ENV:Server', $e->faultcode);
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
