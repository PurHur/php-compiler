<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** openssl_error_string() JIT/AOT lowering (#32336). */
final class OpensslErrorStringJitTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/openssl/openssl_error_string.php');
        $this->assertStringContainsString('JitOpensslError::invoke', $source);
        $this->assertStringNotContainsString(
            'openssl_error_string() is not implemented for JIT',
            $source
        );

        $jit = (string) file_get_contents(__DIR__.'/../../ext/openssl/JitOpensslError.php');
        $this->assertStringContainsString('ERR_get_error', $jit);
        $this->assertStringContainsString('ERR_error_string_n', $jit);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_error_string.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../runtime/openssl_error_string.c');
    }
}
