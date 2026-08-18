<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar XSD_DATETIME DateTimeInterface / json-wire bag (#32269).
 *
 * @covers issue #32269
 */
final class SoapVarXsdDatetimeObjectTest extends TestCase
{
    public function testDateTimeUtcIncludesMicrosecondsAndZ(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $dt = new \DateTime('2023-11-14 22:13:20.123456', new \DateTimeZone('UTC'));
        $xml = $this->encodeParam('param0', $this->bag($dt));
        $this->assertStringContainsString('xsi:type="xsd:dateTime"', $xml);
        $this->assertStringContainsString('>2023-11-14T22:13:20.123456Z<', $xml);
        $this->assertStringNotContainsString('>Array<', $xml);
    }

    public function testDateTimeImmutableUtcMatchesDateTime(): void
    {
        $dt = new \DateTimeImmutable('2023-11-14 22:13:20.123456', new \DateTimeZone('UTC'));
        $xml = $this->encodeParam('param0', $this->bag($dt));
        $this->assertStringContainsString('>2023-11-14T22:13:20.123456Z<', $xml);
    }

    public function testJsonWireBagUtcMatchesDateTimeInterface(): void
    {
        $wire = [
            'date' => '2023-11-14 22:13:20.123456',
            'timezone_type' => 3,
            'timezone' => 'UTC',
        ];
        $xml = $this->encodeParam('param0', $this->bag($wire));
        $this->assertStringContainsString('xsi:type="xsd:dateTime"', $xml);
        $this->assertStringContainsString('>2023-11-14T22:13:20.123456Z<', $xml);
        $this->assertStringNotContainsString('>Array<', $xml);
    }

    public function testUnixTimestampPathUnchangedNoFraction(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $xml = $this->encodeParam('param0', $this->bag(1700000000));
        $this->assertStringContainsString('>2023-11-14T22:13:20Z<', $xml);
        $this->assertStringNotContainsString('.000000', $xml);
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
