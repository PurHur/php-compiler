<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapServer;
use PHPUnit\Framework\TestCase;

/**
 * SoapFault array ($ns, $code) SOAP 1.2 env:Code QName (#31956).
 *
 * @covers issue #31956
 */
final class Soap12FaultCodensTest extends TestCase
{
    private function envelope12(string $code, string $faultcodens = ''): string
    {
        $rm = new \ReflectionMethod(VmSoapServer::class, 'buildSoap12FaultEnvelope');
        $rm->setAccessible(true);
        $xml = $rm->invoke(null, $code, 'nope', '', null, '', '', $faultcodens);
        $this->assertIsString($xml);

        return $xml;
    }

    public function testCustomFaultcodensQNamesValueAndDeclaresNs(): void
    {
        $xml = $this->envelope12('AppError', 'http://example.com/app');
        $this->assertStringContainsString('2003/05/soap-envelope', $xml);
        $this->assertStringContainsString('xmlns:ns1="http://example.com/app"', $xml);
        $this->assertStringContainsString('<env:Value>ns1:AppError</env:Value>', $xml);
        $this->assertStringNotContainsString('<env:Value>AppError</env:Value>', $xml);
        $this->assertStringNotContainsString('faultcode', $xml);
    }

    public function testSoap12EnvFaultcodensReusesEnvPrefix(): void
    {
        $xml = $this->envelope12('Receiver', SoapConstants::SOAP_1_2_ENV_NAMESPACE);
        $this->assertStringContainsString('<env:Value>env:Receiver</env:Value>', $xml);
        $this->assertStringNotContainsString('xmlns:ns1=', $xml);
    }

    public function testEmptyFaultcodensKeepsBareCustomCode(): void
    {
        $xml = $this->envelope12('AppError', '');
        $this->assertStringContainsString('<env:Value>AppError</env:Value>', $xml);
        $this->assertStringNotContainsString('ns1:AppError', $xml);
        $this->assertStringNotContainsString('xmlns:ns1=', $xml);
    }

    public function testSoap11FaultcodeQNameFromFaultcodens(): void
    {
        $rm = new \ReflectionMethod(VmSoapServer::class, 'buildFaultEnvelope');
        $rm->setAccessible(true);
        $xml = $rm->invoke(
            null,
            SoapConstants::SOAP_1_1,
            'AppError',
            'nope',
            '',
            null,
            '',
            '',
            'http://example.com/app'
        );
        $this->assertIsString($xml);
        $this->assertStringContainsString('schemas.xmlsoap.org/soap/envelope', $xml);
        $this->assertStringContainsString('xmlns:ns1="http://example.com/app"', $xml);
        $this->assertStringContainsString('<faultcode>ns1:AppError</faultcode>', $xml);
        $this->assertStringNotContainsString('2003/05/soap-envelope', $xml);
    }

    public function testIssueReproSoap12Faultcodens(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapServer');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_31956_soap_faultcodens_qname.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "PROP_CODE=1\n"
            ."PROP_NS=1\n"
            ."THREW=no\n"
            ."ENV12=1\n"
            ."HAS_APPERROR=1\n"
            ."HAS_APP_NS=1\n"
            ."VALUE_QN=1\n"
            ."VALUE_BARE=0\n"
            ."HAS_FAULTCODE=0\n",
            $out
        );
    }
}
