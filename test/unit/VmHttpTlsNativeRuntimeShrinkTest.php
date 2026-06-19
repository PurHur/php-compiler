<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** VmHttpTlsNative libssl client for https wrapper (#9752). */
final class VmHttpTlsNativeRuntimeShrinkTest extends TestCase
{
    public function testVmHttpTlsNativeDeclaresLibsslSymbols(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHttpTlsNative.php');
        $this->assertStringContainsString('no host ext/openssl stream delegation', $source);
        $this->assertStringContainsString('SSL_connect', $source);
        $this->assertStringContainsString('libssl.so.3', $source);
        $this->assertStringNotContainsString('\\stream_socket_client(', $source);
    }
}
