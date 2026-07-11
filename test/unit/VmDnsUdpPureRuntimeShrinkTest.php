<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmDns;
use PHPCompiler\ext\standard\VmDnsUdpNative;
use PHPCompiler\ext\standard\VmDnsUdpPure;
use PHPUnit\Framework\TestCase;

/** VmDnsUdpPure — UDP DNS without libc socket FFI (#8937, #8092). */
final class VmDnsUdpPureRuntimeShrinkTest extends TestCase
{
    public function testVmDnsUdpNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDnsUdpNative.php');
        $this->assertStringContainsString('VmDnsUdpPure::exchange', $source);
        $this->assertStringContainsString('VmDnsUdpPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->socket/', $source);
    }

    public function testVmDnsUdpPureUsesStreamSocketClientNotLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDnsUdpPure.php');
        $this->assertStringContainsString('stream_socket_client', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int socket(int domain', $source);
    }

    public function testVmDnsDoesNotReferenceHostDnsGetMx(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDns.php');
        $this->assertStringNotContainsString('function_exists(\'dns_get_mx\')', $source);
        $this->assertStringNotContainsString('\\dns_get_mx(', $source);
        $this->assertStringNotContainsString('\\getmxrr(', $source);
        $this->assertStringNotContainsString('stream_socket_client', $source);
    }

    public function testCheckdnsrrExampleComWhenFfiDisabled(): void
    {
        if (!VmDnsUdpPure::available()) {
            $this->markTestSkipped('host stream_socket_client unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmDnsUdpNative::available());

            $result = VmDns::checkdnsrr('example.com', 'A');
            if (false === $result) {
                $this->markTestSkipped('example.com A record unavailable (network)');
            }
            $this->assertTrue($result);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testExampleComMxWhenFfiDisabled(): void
    {
        if (!VmDnsUdpPure::available()) {
            $this->markTestSkipped('host stream_socket_client unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $result = VmDns::dnsGetMx('example.com');
            if (false === $result) {
                $this->markTestSkipped('example.com MX unavailable (network)');
            }
            $this->assertGreaterThan(0, \count($result['hosts']));
            $this->assertSame(\count($result['hosts']), \count($result['weights']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
