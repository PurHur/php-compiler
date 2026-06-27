<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmDns;
use PHPUnit\Framework\TestCase;

/** VmDns dns_get_mx must not delegate to host Zend DNS builtins (#4125). */
final class VmDnsGetMxRuntimeShrinkTest extends TestCase
{
    public function testVmDnsDoesNotReferenceHostDnsGetMx(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDns.php');
        $this->assertStringNotContainsString('function_exists(\'dns_get_mx\')', $source);
        $this->assertStringNotContainsString('\\dns_get_mx(', $source);
        $this->assertStringNotContainsString('\\getmxrr(', $source);
        $this->assertStringNotContainsString('stream_socket_client', $source);
        $this->assertStringNotContainsString('stream_get_contents', $source);
    }

    public function testVmDnsUdpNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/VmDnsUdpNative.php');
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDnsUdpNative.php');
        $this->assertStringContainsString('VmDnsUdpPure::exchange', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->socket/', $source);
    }

    public function testExampleComMxViaPurePath(): void
    {
        $result = VmDns::dnsGetMx('example.com');
        if (false === $result) {
            $this->markTestSkipped('example.com MX unavailable');
        }
        $this->assertGreaterThan(0, \count($result['hosts']));
        $this->assertSame(\count($result['hosts']), \count($result['weights']));
    }
}
