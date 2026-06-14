<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmHttpFetchNative;
use PHPCompiler\ext\standard\VmHttpLastResponseHeaders;
use PHPUnit\Framework\TestCase;

/** VmHttpFetchNative libc HTTP GET without host file_get_contents (#8552). */
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

    public function testVmHttpFetchNativeDeclaresLibcSocketSendRecv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHttpFetchNative.php');
        $this->assertStringContainsString('without host PHP', $source);
        $this->assertStringContainsString('ssize_t send(int sockfd', $source);
        $this->assertStringContainsString('ssize_t recv(int sockfd', $source);
        $this->assertStringContainsString('VmHttpLastResponseHeaders::store', $source);
    }

    public function testHttpsUrlReturnsFalseWithoutHostDelegation(): void
    {
        if (!VmHttpFetchNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmHttpFetchNative');
        }

        VmHttpLastResponseHeaders::clear();
        $this->assertFalse(VmHttpFetchNative::fetch('https://example.com/'));
        $this->assertNull(VmHttpLastResponseHeaders::get());
    }

    public function testHttpFetchPopulatesLastResponseHeaders(): void
    {
        if (!VmHttpFetchNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmHttpFetchNative');
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
}
