<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SOAP 1.2 encoded list arrays: enc:itemType + enc:arraySize (#32220).
 *
 * @covers issue #32220
 */
final class Soap12EncArrayItemTypeTest extends TestCase
{
    public function testSoap12ListUsesItemTypeAndArraySize(): void
    {
        $xml = $this->encodeParam('param0', [1, 2, 3], SoapConstants::SOAP_1_2);
        $this->assertStringContainsString('enc:itemType="xsd:int"', $xml);
        $this->assertStringContainsString('enc:arraySize="3"', $xml);
        $this->assertStringContainsString('xsi:type="enc:Array"', $xml);
        $this->assertStringNotContainsString('SOAP-ENC:arrayType', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:int">1</item>', $xml);
        $this->assertStringContainsString('<item xsi:type="xsd:int">3</item>', $xml);
    }

    public function testSoap11StillUsesArrayType(): void
    {
        $xml = $this->encodeParam('param0', [1, 2, 3], SoapConstants::SOAP_1_1);
        $this->assertStringContainsString('SOAP-ENC:arrayType="xsd:int[3]"', $xml);
        $this->assertStringNotContainsString('enc:itemType', $xml);
        $this->assertStringNotContainsString('enc:arraySize', $xml);
    }

    public function testSoap12SoapEncArrayVar(): void
    {
        $xml = $this->encodeParam(
            'param0',
            [
                'enc_type' => SoapConstants::SOAP_ENC_ARRAY,
                'enc_value' => [1, 2],
                'enc_stype' => null,
                'enc_ns' => null,
                'enc_name' => null,
                'enc_namens' => null,
            ],
            SoapConstants::SOAP_1_2
        );
        $this->assertStringContainsString('enc:itemType="xsd:int"', $xml);
        $this->assertStringContainsString('enc:arraySize="2"', $xml);
        $this->assertStringContainsString('xsi:type="enc:Array"', $xml);
        $this->assertStringNotContainsString('enc_type', $xml);
    }

    public function testSoap12MixedItemsUseUrType(): void
    {
        $xml = $this->encodeParam('param0', [1, 'x'], SoapConstants::SOAP_1_2);
        $this->assertStringContainsString('enc:itemType="xsd:ur-type"', $xml);
        $this->assertStringContainsString('enc:arraySize="2"', $xml);
    }

    /**
     * @param mixed $value
     */
    private function encodeParam(string $name, $value, int $soapVersion): string
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
