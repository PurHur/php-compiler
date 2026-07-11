<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmHttpFetchNative;
use PHPCompiler\ext\standard\VmHttpFetchPure;
use PHPCompiler\ext\standard\VmHttpLastResponseHeaders;
use PHPUnit\Framework\TestCase;

/** VmHttpFetchPure — HTTP GET via VmStreamSocketNative, no duplicate libc socket FFI (#8939). */
final class VmHttpFetchNativeRuntimeShrinkTest extends TestCase
{
    public function testVmFsFileGetContentsRoutesHttpThroughNativeFetch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmHttpFetchNative::fetch', $source);
        $this->assertStringContainsString('VmHttpLastResponseHeaders::isHttpUrl', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/@file_get_contents\\s*\\(/',
            $source,
            'VmFs must not delegate HTTP fetches to host @file_get_contents()'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!\\\\)file_get_contents\\s*\\(/',
            $source,
            'VmFs must not call host file_get_contents()'
        );
    }

    public function testVmHttpFetchNativeDelegatesToPure(): void
    {
        $nativeSource = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHttpFetchNative.php');
        $this->assertStringContainsString('VmHttpFetchPure::', $nativeSource);
        $this->assertStringNotContainsString('FFI::cdef', $nativeSource);
        $this->assertStringNotContainsString('ssize_t send(int sockfd', $nativeSource);
    }

    public function testVmHttpFetchPureUsesStreamSocketNativeNotLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHttpFetchPure.php');
        $this->assertStringContainsString('VmStreamSocketNative::client', $source);
        $this->assertStringContainsString('VmFs::fwrite', $source);
        $this->assertStringContainsString('VmFs::streamGetContents', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('ssize_t send(int sockfd', $source);
        $this->assertStringNotContainsString('without host PHP', $source);
    }

    public function testHttpsFetchPopulatesLastResponseHeadersWhenTlsAvailable(): void
    {
        if (!VmHttpFetchNative::available()) {
            $this->markTestSkipped('VmStreamSocketNative unavailable for VmHttpFetchPure');
        }
        if (!\PHPCompiler\ext\standard\VmHttpTlsNative::available()) {
            $this->markTestSkipped('libssl FFI required for https fetch');
        }

        VmHttpLastResponseHeaders::clear();
        $body = VmFs::fileGetContents('https://example.com');
        if (false === $body) {
            $this->markTestSkipped('network unavailable for https://example.com fetch');
        }

        $headers = VmHttpLastResponseHeaders::get();
        $this->assertIsArray($headers);
        $this->assertNotEmpty($headers);
        $this->assertStringStartsWith('HTTP/', (string) $headers[0]);
        $this->assertIsString($body);
        $this->assertNotSame('', $body);
    }

    public function testHttpsUrlReturnsFalseWhenTlsUnavailable(): void
    {
        if (!VmHttpFetchNative::available()) {
            $this->markTestSkipped('VmStreamSocketNative unavailable for VmHttpFetchPure');
        }
        if (\PHPCompiler\ext\standard\VmHttpTlsNative::available()) {
            $this->markTestSkipped('libssl available — use testHttpsFetchPopulatesLastResponseHeadersWhenTlsAvailable');
        }

        VmHttpLastResponseHeaders::clear();
        $this->assertFalse(VmHttpFetchNative::fetch('https://example.com/'));
        $this->assertNull(VmHttpLastResponseHeaders::get());
    }

    public function testHttpFetchPopulatesLastResponseHeaders(): void
    {
        if (!VmHttpFetchNative::available()) {
            $this->markTestSkipped('VmStreamSocketNative unavailable for VmHttpFetchPure');
        }

        VmHttpLastResponseHeaders::clear();
        $body = VmFs::fileGetContents('http://example.com');
        if (false === $body) {
            $this->markTestSkipped('network unavailable for http://example.com fetch');
        }

        $headers = VmHttpLastResponseHeaders::get();
        $this->assertIsArray($headers);
        $this->assertNotEmpty($headers);
        $this->assertStringStartsWith('HTTP/', (string) $headers[0]);
        $this->assertIsString($body);
        $this->assertNotSame('', $body);
    }

    public function testHttpFetchWorksWhenFfiDisabled(): void
    {
        if (!VmHttpFetchPure::available()) {
            $this->markTestSkipped('VmStreamSocketNative unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmHttpFetchPure::available());

            $body = VmHttpFetchPure::fetch('http://example.com/');
            if (false === $body) {
                $this->markTestSkipped('network unavailable for http://example.com fetch with FFI disabled');
            }
            $this->assertIsString($body);
            $this->assertNotSame('', $body);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
