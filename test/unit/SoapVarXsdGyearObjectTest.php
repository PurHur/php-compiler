<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar XSD_GYEAR / gMonth / gDay DateTimeInterface (#32271).
 *
 * @covers issue #32271
 */
final class SoapVarXsdGyearObjectTest extends TestCase
{
    public function testDateTimeUtcGyearFamily(): void
    {
        $dt = new \DateTime('2023-11-14 22:13:20.123456', new \DateTimeZone('UTC'));
        $cases = [
            [SoapConstants::XSD_GYEAR, 'xsd:gYear', '2023Z'],
            [SoapConstants::XSD_GYEARMONTH, 'xsd:gYearMonth', '2023-11Z'],
            [SoapConstants::XSD_GMONTHDAY, 'xsd:gMonthDay', '--11-14Z'],
            [SoapConstants::XSD_GDAY, 'xsd:gDay', '---14Z'],
            [SoapConstants::XSD_GMONTH, 'xsd:gMonth', '--11--Z'],
        ];
        foreach ($cases as [$encType, $xsi, $expect]) {
            $xml = $this->encodeParam('param0', $this->bag($encType, $dt));
            $this->assertStringContainsString('xsi:type="'.$xsi.'"', $xml);
            $this->assertStringContainsString('>'.$expect.'<', $xml);
            $this->assertStringNotContainsString('>Array<', $xml);
        }
    }

    public function testJsonWireBagUtcGyearFamily(): void
    {
        $wire = [
            'date' => '2023-11-14 22:13:20.123456',
            'timezone_type' => 3,
            'timezone' => 'UTC',
        ];
        $xml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_GYEARMONTH, $wire));
        $this->assertStringContainsString('>2023-11Z<', $xml);
        $this->assertStringNotContainsString('>Array<', $xml);
    }

    public function testUnixTimestampPathUnchanged(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $xml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_GYEAR, 1700000000));
        $this->assertStringContainsString('>2023Z<', $xml);
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
