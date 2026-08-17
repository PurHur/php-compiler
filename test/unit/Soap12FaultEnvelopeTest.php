<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapServer;
use PHPUnit\Framework\TestCase;

/**
 * SoapServer::fault SOAP 1.2 env:Code/Reason envelope (#31944).
 *
 * @covers issue #31944
 */
final class Soap12FaultEnvelopeTest extends TestCase
{
    private function envelope(string $code, string $string = 'nope12', string $lang = ''): string
    {
        $rm = new \ReflectionMethod(VmSoapServer::class, 'buildSoap12FaultEnvelope');
        $rm->setAccessible(true);
        $xml = $rm->invoke(null, $code, $string, '', null, '', $lang);
        $this->assertIsString($xml);

        return $xml;
    }

    public function testReceiverPassedDirectlyIsUnprefixed(): void
    {
        $xml = $this->envelope('Receiver');
        $this->assertStringContainsString('2003/05/soap-envelope', $xml);
        $this->assertStringContainsString('<env:Code>', $xml);
        $this->assertStringContainsString('<env:Reason>', $xml);
        $this->assertStringContainsString('<env:Value>Receiver</env:Value>', $xml);
        $this->assertStringNotContainsString('<env:Value>env:Receiver</env:Value>', $xml);
        $this->assertStringNotContainsString('schemas.xmlsoap.org/soap/envelope', $xml);
        $this->assertStringNotContainsString('faultcode', $xml);
        $this->assertStringNotContainsString('xml:lang', $xml);
    }

    public function testServerMapsToEnvReceiverQName(): void
    {
        $xml = $this->envelope('Server');
        $this->assertStringContainsString('<env:Value>env:Receiver</env:Value>', $xml);
        $this->assertStringNotContainsString('<env:Value>Receiver</env:Value>', $xml);
    }

    public function testClientMapsToEnvSenderQName(): void
    {
        $xml = $this->envelope('Client');
        $this->assertStringContainsString('<env:Value>env:Sender</env:Value>', $xml);
    }

    public function testStandardSoap12CodesAreQNames(): void
    {
        $xml = $this->envelope('VersionMismatch');
        $this->assertStringContainsString('<env:Value>env:VersionMismatch</env:Value>', $xml);
        $xml = $this->envelope('MustUnderstand');
        $this->assertStringContainsString('<env:Value>env:MustUnderstand</env:Value>', $xml);
        $xml = $this->envelope('DataEncodingUnknown');
        $this->assertStringContainsString('<env:Value>env:DataEncodingUnknown</env:Value>', $xml);
    }

    public function testCustomCodeIsUnprefixed(): void
    {
        $xml = $this->envelope('Custom');
        $this->assertStringContainsString('<env:Value>Custom</env:Value>', $xml);
        $this->assertStringNotContainsString('env:Custom', $xml);
    }

    public function testLangSetsXmlLangOnReasonText(): void
    {
        $xml = $this->envelope('Receiver', 'nope12', 'en');
        $this->assertStringContainsString('<env:Text xml:lang="en">nope12</env:Text>', $xml);
    }

    public function testSoap11EnvelopeUnchanged(): void
    {
        $rm = new \ReflectionMethod(VmSoapServer::class, 'buildFaultEnvelope');
        $rm->setAccessible(true);
        $xml = $rm->invoke(null, SoapConstants::SOAP_1_1, 'Server', 'nope11');
        $this->assertIsString($xml);
        $this->assertStringContainsString('schemas.xmlsoap.org/soap/envelope', $xml);
        $this->assertStringContainsString('<faultcode>Server</faultcode>', $xml);
        $this->assertStringContainsString('<faultstring>nope11</faultstring>', $xml);
        $this->assertStringNotContainsString('2003/05/soap-envelope', $xml);
        $this->assertStringNotContainsString('<env:Code>', $xml);
    }

    public function testIssueReproSoap12Envelope(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapServer');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_31944_soap12_fault_envelope.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "THREW=no\n"
            ."ENV12=1\n"
            ."ENV11=0\n"
            ."HAS_FAULT=1\n"
            ."HAS_CODE=1\n"
            ."HAS_REASON=1\n"
            ."VALUE_BARE=1\n"
            ."VALUE_QN=0\n"
            ."HAS_FAULTCODE=0\n"
            ."HAS_LANG=0\n"
            ."SERVER_QN=1\n"
            ."SERVER_BARE=0\n",
            $out
        );
    }
}
