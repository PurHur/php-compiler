<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use ArrayIterator;
use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar SOAP_ENC_ARRAY to_xml_array — values as item, including string keys (#32284).
 *
 * @covers issue #32284
 */
final class SoapVarEncArrayTest extends TestCase
{
    public function testAssocMapEmitsItemsNotKeys(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(['a' => 1, 'b' => 2]));
        $this->assertStringContainsString('xsi:type="SOAP-ENC:Array"', $xml);
        $this->assertStringContainsString('SOAP-ENC:arrayType="xsd:int[2]"', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:int">1</item>', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:int">2</item>', $xml);
        $this->assertStringNotContainsString('<a xsi:type="xsd:int">', $xml);
        $this->assertStringNotContainsString('<b xsi:type="xsd:int">', $xml);
    }

    public function testListArrayStillMatches(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(['x', 'y']));
        $this->assertStringContainsString('xsi:type="SOAP-ENC:Array"', $xml);
        $this->assertStringContainsString('SOAP-ENC:arrayType="xsd:string[2]"', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:string">x</item>', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:string">y</item>', $xml);
    }

    public function testSoap12AssocUsesEncArrayPrefix(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(['a' => 1, 'b' => 2]), SoapConstants::SOAP_1_2);
        $this->assertStringContainsString('xsi:type="enc:Array"', $xml);
        $this->assertStringContainsString('enc:itemType="xsd:int"', $xml);
        $this->assertStringContainsString('enc:arraySize="2"', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:int">1</item>', $xml);
        $this->assertStringNotContainsString('SOAP-ENC:Array', $xml);
        $this->assertStringNotContainsString('<a xsi:type="xsd:int">', $xml);
    }

    public function testTraversableYieldsItems(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(new ArrayIterator(['a' => 7, 'b' => 8])));
        $this->assertStringContainsString('xsi:type="SOAP-ENC:Array"', $xml);
        $this->assertStringContainsString('SOAP-ENC:arrayType="xsd:int[2]"', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:int">7</item>', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:int">8</item>', $xml);
        $this->assertStringNotContainsString('<a xsi:type="xsd:int">', $xml);
    }

    public function testNullIsNilArray(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(null));
        $this->assertStringContainsString('xsi:type="SOAP-ENC:Array"', $xml);
        $this->assertStringContainsString('xsi:nil="true"', $xml);
        $this->assertStringNotContainsString('arrayType', $xml);
    }

    public function testEncNameRenamesElement(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(['x', 'y'], 'items'));
        $this->assertStringStartsWith('<items ', $xml);
        $this->assertStringContainsString('xsi:type="SOAP-ENC:Array"', $xml);
        $this->assertStringContainsString('</items>', $xml);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function bag($value, ?string $encName = null): array
    {
        return [
            'enc_type' => SoapConstants::SOAP_ENC_ARRAY,
            'enc_value' => $value,
            'enc_stype' => null,
            'enc_ns' => null,
            'enc_name' => $encName,
            'enc_namens' => null,
        ];
    }

    /**
     * @param mixed $value
     */
    private function encodeParam(string $name, $value, int $soapVersion = SoapConstants::SOAP_1_1): string
    {
        class_exists(VmSoapClient::class);
        $state = new SoapClientState();
        $state->use = SoapConstants::SOAP_ENCODED;
        $state->style = SoapConstants::SOAP_RPC;
        $state->uri = 'http://example.com/echo';
        $state->soapVersion = $soapVersion;
        $rm = new \ReflectionMethod(VmSoapClient::class, 'encodeParam');
        $rm->setAccessible(true);

        return (string) $rm->invoke(null, $name, $value, $state, null);
    }
}
