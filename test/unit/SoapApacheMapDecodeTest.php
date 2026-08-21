<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapClient APACHE_MAP decode — php-src to_zval_map (#3724, ext/soap/php_encoding.c).
 *
 * @covers issue #3724
 */
final class SoapApacheMapDecodeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\class_exists(\SoapFault::class, false)) {
            require_once dirname(__DIR__, 2).'/ext/soap/bootstrap_soapfault.php';
        }
    }

    public function testMapDecodesToAssociativeArray(): void
    {
        $out = $this->decode($this->envelope(
            '<return xsi:type="ns2:Map" xmlns:ns2="http://xml.apache.org/xml-soap">'
            .'<item><key xsi:type="xsd:string">k</key><value xsi:type="xsd:string">v</value></item>'
            .'<item><key xsi:type="xsd:int">7</key><value xsi:type="xsd:string">x</value></item>'
            .'</return>'
        ));
        $this->assertIsArray($out);
        $this->assertSame(['k' => 'v', 7 => 'x'], $out);
    }

    public function testEmptyMapIsNull(): void
    {
        $out = $this->decode($this->envelope(
            '<return xsi:type="ns2:Map" xmlns:ns2="http://xml.apache.org/xml-soap"/>'
        ));
        $this->assertNull($out);
    }

    public function testMissingKeyThrowsSoapFault(): void
    {
        $this->expectException(\SoapFault::class);
        $this->expectExceptionMessage("Encoding: Can't decode apache map, missing key");
        $this->decode($this->envelope(
            '<return xsi:type="ns2:Map" xmlns:ns2="http://xml.apache.org/xml-soap">'
            .'<item><value xsi:type="xsd:string">v</value></item>'
            .'</return>'
        ));
    }

    private function envelope(string $bodyInner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:ns1="http://example.com/echo"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            .'<SOAP-ENV:Body><ns1:echoResponse>'
            .$bodyInner
            .'</ns1:echoResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    private function decode(string $xml): mixed
    {
        $rm = new \ReflectionMethod(VmSoapClient::class, 'decodeResponse');
        $rm->setAccessible(true);

        return $rm->invoke(null, $xml, 'echo');
    }
}
