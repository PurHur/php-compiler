<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar APACHE_MAP item/key/value (#32222).
 *
 * @covers issue #32222
 */
final class SoapVarApacheMapTest extends TestCase
{
    public function testMapItemsKeyValueAndNs(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(['k' => 'v']));
        $this->assertStringContainsString('xmlns:ns2="http://xml.apache.org/xml-soap"', $xml);
        $this->assertStringContainsString('xsi:type="ns2:Map"', $xml);
        $this->assertStringContainsString('<item><key xsi:type="xsd:string">k</key><value xsi:type="xsd:string">v</value></item>', $xml);
        $this->assertStringNotContainsString('enc_type', $xml);
        $this->assertStringNotContainsString('apache:Map', $xml);
    }

    public function testIntegerKeysUseXsdInt(): void
    {
        $xml = $this->encodeParam('param0', $this->bag([7 => 'x']));
        $this->assertStringContainsString('<key xsi:type="xsd:int">7</key>', $xml);
        $this->assertStringContainsString('<value xsi:type="xsd:string">x</value>', $xml);
    }

    /**
     * @param array<string|int, mixed> $value
     * @return array<string, mixed>
     */
    private function bag(array $value): array
    {
        return [
            'enc_type' => SoapConstants::APACHE_MAP,
            'enc_value' => $value,
            'enc_stype' => null,
            'enc_ns' => null,
            'enc_name' => null,
            'enc_namens' => null,
        ];
    }

    /**
     * @param mixed $value
     */
    private function encodeParam(string $name, $value): string
    {
        class_exists(VmSoapClient::class);
        $state = new SoapClientState();
        $state->use = SoapConstants::SOAP_ENCODED;
        $state->style = SoapConstants::SOAP_RPC;
        $state->uri = 'http://example.com/echo';
        $rm = new \ReflectionMethod(VmSoapClient::class, 'encodeParam');
        $rm->setAccessible(true);

        return (string) $rm->invoke(null, $name, $value, $state, null);
    }
}
