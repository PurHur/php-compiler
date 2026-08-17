<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\soap\VmSoapServer;
use PHPUnit\Framework\TestCase;

/**
 * SoapServer SOAP 1.2/1.1 handle() transport headers (#31957).
 *
 * @covers issue #31957
 */
final class Soap12FaultHttpHeadersTest extends TestCase
{
    public function testResponseContentTypeHeaderSoap12And11(): void
    {
        $this->assertSame(
            'Content-Type: application/soap+xml; charset=utf-8',
            VmSoapServer::responseContentTypeHeader(SoapConstants::SOAP_1_2)
        );
        $this->assertSame(
            'Content-Type: text/xml; charset=utf-8',
            VmSoapServer::responseContentTypeHeader(SoapConstants::SOAP_1_1)
        );
    }

    public function testIssueReproSoap12FaultHttpHeaders(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapServer');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_31957_soap12_fault_http_headers.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $env = [];
        foreach (\array_merge($_ENV, $_SERVER) as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['GATEWAY_INTERFACE'] = 'CGI/1.1';
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open([$php, $vm, $script], $descriptor, $pipes, $root, $env);
        $this->assertIsResource($proc);
        \fclose($pipes[0]);
        $out = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);
        $this->assertSame(0, $code, \trim((false !== $stderr ? $stderr : '')."\n".(false !== $out ? $out : '')));
        $this->assertSame(
            "status500=1\n"
            ."ct12=1\n"
            ."no_text=1\n",
            $out
        );
    }
}
