<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapVar XSD_NMTOKENS / IDREFS / ENTITIES to_xml_list (#32272).
 *
 * @covers issue #32272
 */
final class SoapVarXsdNmtokensListTest extends TestCase
{
    public function testNmtokensArrayJoinsWithSpace(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_NMTOKENS, ['a', 'b', 'c']));
        $this->assertStringContainsString('xsi:type="xsd:NMTOKENS"', $xml);
        $this->assertStringContainsString('>a b c<', $xml);
        $this->assertStringNotContainsString('>Array<', $xml);
    }

    public function testIdrefsAndEntitiesArrays(): void
    {
        $idrefs = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_IDREFS, ['id1', 'id2']));
        $this->assertStringContainsString('xsi:type="xsd:IDREFS"', $idrefs);
        $this->assertStringContainsString('>id1 id2<', $idrefs);

        $entities = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_ENTITIES, ['e1', 'e2']));
        $this->assertStringContainsString('xsi:type="xsd:ENTITIES"', $entities);
        $this->assertStringContainsString('>e1 e2<', $entities);
    }

    public function testStringPayloadWhitespaceCollapse(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_NMTOKENS, "  a \t b\n c  "));
        $this->assertStringContainsString('>a b c<', $xml);
    }

    public function testArrayItemInternalSpacesPreserved(): void
    {
        $xml = $this->encodeParam('param0', $this->bag(SoapConstants::XSD_NMTOKENS, ['a b', 'c']));
        $this->assertStringContainsString('>a b c<', $xml);
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
