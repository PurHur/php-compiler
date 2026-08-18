<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapClient;
use PHPUnit\Framework\TestCase;

/**
 * SoapParam param_name as RPC element (#32193).
 *
 * @covers issue #32193
 */
final class SoapParamRpcNameTest extends TestCase
{
    public function testParamNameBecomesElement(): void
    {
        $xml = $this->encodeParam('param0', [
            'param_name' => 'input',
            'param_data' => 'hello',
        ]);
        $this->assertStringContainsString('<input xsi:type="xsd:string">hello</input>', $xml);
        $this->assertStringNotContainsString('param0', $xml);
        $this->assertStringNotContainsString('param_name', $xml);
        $this->assertStringNotContainsString('param_data', $xml);
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
