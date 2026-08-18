<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** openssl_pbkdf2() JIT/AOT lowering (#32410 leftover of #6488). */
final class OpensslPbkdf2JitTest extends TestCase
{
    public function testCallDelegatesToCompileTimeBake(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/openssl/openssl_pbkdf2.php');
        $this->assertStringContainsString('JitOpensslPbkdf2::invoke', $source);
        $this->assertStringNotContainsString(
            'openssl_pbkdf2() is not implemented for JIT',
            $source
        );

        $jit = (string) file_get_contents(__DIR__.'/../../ext/openssl/JitOpensslPbkdf2.php');
        $this->assertStringContainsString('VmHashNative::hashPbkdf2', $jit);
        $this->assertStringContainsString('Argument #3 ($key_length) must be greater than 0', $jit);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_pbkdf2.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../runtime/openssl_pbkdf2.c');
    }
}
