<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar SOAP_ENC_OBJECT → SOAP-ENC:Struct (#32192).
 *
 * @covers issue #32192
 */
final class SoapVarEncObjectTest extends TestCase
{
    public function testSoap11StructAndChildren(): void
    {
        $xml = $this->encodeParam(
            'param0',
            $this->bag(['a' => 1, 'b' => 'x']),
            SoapConstants::SOAP_1_1
        );
        $this->assertStringContainsString('xsi:type="SOAP-ENC:Struct"', $xml);
        $this->assertStringContainsString('<a xsi:type="xsd:int">1</a>', $xml);
        $this->assertStringContainsString('<b xsi:type="xsd:string">x</b>', $xml);
        $this->assertStringNotContainsString('enc_type', $xml);
    }

    public function testSoap12UsesEncStructPrefix(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(['a' => 1]), SoapConstants::SOAP_1_2);
        $this->assertStringContainsString('xsi:type="enc:Struct"', $xml);
        $this->assertStringNotContainsString('SOAP-ENC:Struct', $xml);
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function bag(array $value): array
    {
        return [
            'enc_type' => SoapConstants::SOAP_ENC_OBJECT,
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
