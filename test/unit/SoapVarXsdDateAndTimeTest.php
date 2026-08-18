<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar XSD_DATE / XSD_TIME unix timestamps (#32239).
 *
 * @covers issue #32239
 */
final class SoapVarXsdDateAndTimeTest extends TestCase
{
    public function testUnixTimestampUtcDateAndTime(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $date = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_DATE, 1700000000));
        $this->assertStringContainsString('xsi:type="xsd:date"', $date);
        $this->assertStringContainsString('>2023-11-14Z<', $date);
        $this->assertStringNotContainsString('>1700000000<', $date);

        $time = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_TIME, 1700000000));
        $this->assertStringContainsString('xsi:type="xsd:time"', $time);
        $this->assertStringContainsString('>22:13:20Z<', $time);
        $this->assertStringNotContainsString('>1700000000<', $time);
    }

    public function testDatetimeRegressionStillIso8601(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $xml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_DATETIME, 1700000000));
        $this->assertStringContainsString('>2023-11-14T22:13:20Z<', $xml);
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
