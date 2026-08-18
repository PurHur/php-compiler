<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * openssl_pbkdf2() JIT/AOT lowering (#32410 leftover of #6488).
 *
 * Compile-time bake ({@see \PHPCompiler\ext\openssl\JitOpensslPbkdf2}) cannot lower
 * runtime password/salt/key_length; call() uses OpensslPbkdf2Runtime instead.
 */
final class OpensslPbkdf2JitTest extends TestCase
{
    public function testCallDelegatesToRuntimeKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/openssl/openssl_pbkdf2.php');
        $this->assertStringContainsString('OpensslPbkdf2Runtime::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_openssl_pbkdf2', $source);
        $this->assertStringNotContainsString('JitOpensslPbkdf2::invoke', $source);
        $this->assertStringNotContainsString(
            'openssl_pbkdf2() is not implemented for JIT',
            $source
        );

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_pbkdf2.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../runtime/openssl_pbkdf2.c');
    }
}
