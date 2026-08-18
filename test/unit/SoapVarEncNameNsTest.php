<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar enc_name / enc_namens element naming (#32191).
 *
 * @covers issue #32191
 */
final class SoapVarEncNameNsTest extends TestCase
{
    public function testEncNameReplacesParamPlaceholder(): void
    {
        $xml = $this->encodeParam('param0', $this->bag('hello', 'input', null));
        $this->assertStringContainsString('<input xsi:type="xsd:string">hello</input>', $xml);
        $this->assertStringNotContainsString('param0', $xml);
    }

    public function testEncNamensMatchingUriUsesNs1Prefix(): void
    {
        $xml = $this->encodeParam('param0', $this->bag('hello', 'input', 'http://example.com/echo'));
        $this->assertStringContainsString('<ns1:input xsi:type="xsd:string">hello</ns1:input>', $xml);
    }

    public function testEncNamensOtherUriDeclaresNs2(): void
    {
        $xml = $this->encodeParam('param0', $this->bag('hello', 'input', 'urn:other'));
        $this->assertStringContainsString('xmlns:ns2="urn:other"', $xml);
        $this->assertStringContainsString('<ns2:input', $xml);
        $this->assertStringContainsString('</ns2:input>', $xml);
    }

    /**
     * @return array<string, mixed>
     */
    private function bag(string $value, ?string $name, ?string $namens): array
    {
        return [
            'enc_type' => SoapConstants::XSD_STRING,
            'enc_value' => $value,
            'enc_stype' => null,
            'enc_ns' => null,
            'enc_name' => $name,
            'enc_namens' => $namens,
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
