<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapServer;
use PHPUnit\Framework\TestCase;

/**
 * SoapServer::fault SOAP 1.2 env:Detail; actor omitted from envelope (#31945).
 *
 * @covers issue #31945
 */
final class Soap12FaultActorDetailTest extends TestCase
{
    public function testSoap12EnvelopeBuilderMatchesPhpSrc(): void
    {
        $rm = new \ReflectionMethod(VmSoapServer::class, 'buildSoap12FaultEnvelope');
        $rm->setAccessible(true);
        $xml = $rm->invoke(null, 'Server', 'msg', 'http://actor', 'det', '');
        $this->assertIsString($xml);
        $this->assertStringContainsString('2003/05/soap-envelope', $xml);
        $this->assertStringContainsString('env:Receiver', $xml);
        $this->assertStringContainsString('<env:Detail>det</env:Detail>', $xml);
        $this->assertStringNotContainsString('schemas.xmlsoap.org/soap/envelope', $xml);
        $this->assertStringNotContainsString('Role', $xml);
        $this->assertStringNotContainsString('Node', $xml);
        $this->assertStringNotContainsString('http://actor', $xml);
        $this->assertStringNotContainsString('<detail>', $xml);
    }

    public function testSoap11EnvelopeStillEmitsFaultactor(): void
    {
        $rm = new \ReflectionMethod(VmSoapServer::class, 'buildFaultEnvelope');
        $rm->setAccessible(true);
        $xml = $rm->invoke(null, SoapConstants::SOAP_1_1, 'Server', 'msg', 'http://actor', 'det', '');
        $this->assertIsString($xml);
        $this->assertStringContainsString('<faultactor>http://actor</faultactor>', $xml);
        $this->assertStringContainsString('<detail>det</detail>', $xml);
    }

    public function testSoap12FaultOmitsActorEmitsDetail(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapServer');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_31945_soap12_fault_actor_detail.php';
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
            ."HAS_RECEIVER=1\n"
            ."HAS_NODE=0\n"
            ."HAS_ROLE=0\n"
            ."HAS_ACTOR_URI=0\n"
            ."HAS_ENV_DETAIL=1\n"
            ."HAS_11_DETAIL=0\n"
            ."HAS_DET=1\n",
            $out
        );
    }
}
