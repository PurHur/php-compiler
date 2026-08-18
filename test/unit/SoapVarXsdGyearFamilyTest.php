<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar XSD_GYEAR / gMonth / gDay unix timestamps (#32240).
 *
 * @covers issue #32240
 */
final class SoapVarXsdGyearFamilyTest extends TestCase
{
    public function testUnixTimestampUtcGyearFamily(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $cases = [
            [SoapConstants::XSD_GYEAR, 'xsd:gYear', '2023Z'],
            [SoapConstants::XSD_GYEARMONTH, 'xsd:gYearMonth', '2023-11Z'],
            [SoapConstants::XSD_GMONTHDAY, 'xsd:gMonthDay', '--11-14Z'],
            [SoapConstants::XSD_GDAY, 'xsd:gDay', '---14Z'],
            [SoapConstants::XSD_GMONTH, 'xsd:gMonth', '--11--Z'],
        ];
        foreach ($cases as [$encType, $xsi, $expect]) {
            $xml = $this->encodeParam('param0', $this->bag($encType, 1700000000));
            $this->assertStringContainsString('xsi:type="'.$xsi.'"', $xml);
            $this->assertStringContainsString('>'.$expect.'<', $xml);
            $this->assertStringNotContainsString('>1700000000<', $xml);
        }
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
