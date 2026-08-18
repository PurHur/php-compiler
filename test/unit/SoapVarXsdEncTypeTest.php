<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar enc_type unwrap + XSD xsi:type (#32190).
 *
 * @covers issue #32190
 */
final class SoapVarXsdEncTypeTest extends TestCase
{
    public function testXsdStringDoesNotEmitPropertyBag(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_STRING, 123));
        $this->assertStringContainsString('xsi:type="xsd:string"', $xml);
        $this->assertStringContainsString('>123<', $xml);
        $this->assertStringNotContainsString('enc_type', $xml);
        $this->assertStringNotContainsString('enc_value', $xml);
    }

    public function testXsdIntBooleanFloatBase64Hex(): void
    {
        $int = $this->encodeParam('p', $this->bag(SoapConstants::XSD_INT, '7'));
        $this->assertStringContainsString('xsi:type="xsd:int"', $int);
        $this->assertStringContainsString('>7<', $int);

        $bool = $this->encodeParam('p', $this->bag(SoapConstants::XSD_BOOLEAN, 1));
        $this->assertStringContainsString('xsi:type="xsd:boolean"', $bool);
        $this->assertStringContainsString('>true<', $bool);

        $float = $this->encodeParam('p', $this->bag(SoapConstants::XSD_FLOAT, '1.5'));
        $this->assertStringContainsString('xsi:type="xsd:float"', $float);
        $this->assertStringContainsString('>1.5<', $float);

        $b64 = $this->encodeParam('p', $this->bag(SoapConstants::XSD_BASE64BINARY, 'hi'));
        $this->assertStringContainsString('xsi:type="xsd:base64Binary"', $b64);
        $this->assertStringContainsString('>'.\base64_encode('hi').'<', $b64);

        $hex = $this->encodeParam('p', $this->bag(SoapConstants::XSD_HEXBINARY, 'hi'));
        $this->assertStringContainsString('xsi:type="xsd:hexBinary"', $hex);
        $this->assertStringContainsString('>'.\strtoupper(\bin2hex('hi')).'<', $hex);
    }

    public function testLiteralOmitsXsiType(): void
    {
        $state = new SoapClientState();
        $state->use = SoapConstants::SOAP_LITERAL;
        $xml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_STRING, 'hello'), $state);
        $this->assertStringNotContainsString('xsi:type', $xml);
        $this->assertStringContainsString('>hello<', $xml);
    }

    /**
     * @param mixed $value
     */
    private function bag(int $encType, $value): array
    {
        return [
            'enc_type' => $encType,
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
    private function encodeParam(string $name, $value, ?SoapClientState $state = null): string
    {
        class_exists(VmSoapClient::class);
        if (null === $state) {
            $state = new SoapClientState();
            $state->use = SoapConstants::SOAP_ENCODED;
            $state->style = SoapConstants::SOAP_RPC;
        }
        $rm = new \ReflectionMethod(VmSoapClient::class, 'encodeParam');
        $rm->setAccessible(true);

        return (string) $rm->invoke(null, $name, $value, $state, null);
    }
}
