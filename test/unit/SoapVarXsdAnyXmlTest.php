<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar XSD_ANYXML embeds raw XML without wrapper (#32241).
 *
 * @covers issue #32241
 */
final class SoapVarXsdAnyXmlTest extends TestCase
{
    public function testRawXmlFragmentIsNotEscapedOrWrapped(): void
    {
        $xml = $this->encodeParam('param0', $this->bag('<raw>x</raw>'));
        $this->assertSame('<raw>x</raw>', $xml);
        $this->assertStringNotContainsString('param0', $xml);
        $this->assertStringNotContainsString('xsi:type', $xml);
        $this->assertStringNotContainsString('&lt;', $xml);
    }

    public function testPlainTextIsUnwrappedAndUntyped(): void
    {
        $xml = $this->encodeParam('param0', $this->bag('plain'));
        $this->assertSame('plain', $xml);
        $this->assertStringNotContainsString('param0', $xml);
        $this->assertStringNotContainsString('xsi:type', $xml);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function bag($value): array
    {
        return [
            'enc_type' => SoapConstants::XSD_ANYXML,
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
