<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar XSD_DATETIME unix timestamp ISO-8601 (#32237).
 *
 * @covers issue #32237
 */
final class SoapVarXsdDatetimeTest extends TestCase
{
    public function testUnixTimestampUtcIsIso8601WithZ(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $xml = $this->encodeParam('param0', $this->bag(1700000000));
        $this->assertStringContainsString('xsi:type="xsd:dateTime"', $xml);
        $this->assertStringContainsString('>2023-11-14T22:13:20Z<', $xml);
        $this->assertStringNotContainsString('>1700000000<', $xml);
    }

    public function testStringPayloadPassesThrough(): void
    {
        $xml = $this->encodeParam('param0', $this->bag('already-iso'));
        $this->assertStringContainsString('xsi:type="xsd:dateTime"', $xml);
        $this->assertStringContainsString('>already-iso<', $xml);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function bag($value): array
    {
        return [
            'enc_type' => SoapConstants::XSD_DATETIME,
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
