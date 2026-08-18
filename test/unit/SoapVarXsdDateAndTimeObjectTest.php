<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar XSD_DATE / XSD_TIME DateTimeInterface (#32270).
 *
 * @covers issue #32270
 */
final class SoapVarXsdDateAndTimeObjectTest extends TestCase
{
    public function testDateTimeUtcDateAndTimeFormats(): void
    {
        $dt = new \DateTime('2023-11-14 22:13:20.123456', new \DateTimeZone('UTC'));
        $dateXml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_DATE, $dt));
        $this->assertStringContainsString('xsi:type="xsd:date"', $dateXml);
        $this->assertStringContainsString('>2023-11-14Z<', $dateXml);
        $this->assertStringNotContainsString('>Array<', $dateXml);

        $timeXml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_TIME, $dt));
        $this->assertStringContainsString('xsi:type="xsd:time"', $timeXml);
        $this->assertStringContainsString('>22:13:20.123456Z<', $timeXml);
        $this->assertStringNotContainsString('>Array<', $timeXml);
    }

    public function testJsonWireBagUtcDateAndTimeFormats(): void
    {
        $wire = [
            'date' => '2023-11-14 22:13:20.123456',
            'timezone_type' => 3,
            'timezone' => 'UTC',
        ];
        $dateXml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_DATE, $wire));
        $this->assertStringContainsString('>2023-11-14Z<', $dateXml);
        $timeXml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_TIME, $wire));
        $this->assertStringContainsString('>22:13:20.123456Z<', $timeXml);
    }

    public function testUnixTimestampPathUnchanged(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $dateXml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_DATE, 1700000000));
        $this->assertStringContainsString('>2023-11-14Z<', $dateXml);
        $timeXml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_TIME, 1700000000));
        $this->assertStringContainsString('>22:13:20Z<', $timeXml);
        $this->assertStringNotContainsString('.000000', $timeXml);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
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
