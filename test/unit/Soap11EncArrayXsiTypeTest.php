<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SOAP 1.1 encoded list arrays always xsi:type SOAP-ENC:Array (#32221).
 *
 * @covers issue #32221
 */
final class Soap11EncArrayXsiTypeTest extends TestCase
{
    public function testDefaultEncodedListHasArrayXsiType(): void
    {
        $xml = $this->encodeParam('param0', [1, 2], 0);
        $this->assertStringContainsString('SOAP-ENC:arrayType="xsd:int[2]"', $xml);
        $this->assertStringContainsString('xsi:type="SOAP-ENC:Array"', $xml);
    }

    public function testFeatureFlagDoesNotChangeUntypedArray(): void
    {
        $plain = $this->encodeParam('param0', [1, 2], 0);
        $feat = $this->encodeParam('param0', [1, 2], SoapConstants::SOAP_USE_XSI_ARRAY_TYPE);
        $this->assertSame($plain, $feat);
    }

    public function testSoapEncArrayVarHasArrayXsiType(): void
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
            0
        );
        $this->assertStringContainsString('xsi:type="SOAP-ENC:Array"', $xml);
        $this->assertStringNotContainsString('enc_type', $xml);
    }

    /**
     * @param mixed $value
     */
    private function encodeParam(string $name, $value, int $features): string
    {
        class_exists(VmSoapClient::class);
        $state = new SoapClientState();
        $state->use = SoapConstants::SOAP_ENCODED;
        $state->style = SoapConstants::SOAP_RPC;
        $state->uri = 'http://example.com/echo';
        $state->soapVersion = SoapConstants::SOAP_1_1;
        $state->features = $features;
        $rm = new \ReflectionMethod(VmSoapClient::class, 'encodeParam');
        $rm->setAccessible(true);

        return (string) $rm->invoke(null, $name, $value, $state, null);
    }
}
