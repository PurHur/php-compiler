<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #7060: hash crypto LLVM helpers must lower without hash_crypto.c.
 *
 * @group aot-lint
 */
final class StringHashCryptoRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesHashCryptoC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/hash_crypto.c');
        $this->assertFileExists(__DIR__.'/../../../lib/JIT/Builtin/hash_crypto_jit_runtime.c');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoJit.php');
        $this->assertStringContainsString('__compiler_hash', $jit);
        $this->assertStringContainsString('StringHashCryptoJit', $jit);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('runtime/hash_crypto.c', $linker);
        $this->assertStringContainsString('hash_crypto_jit_runtime.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCrypto.php');
        $this->assertStringContainsString('StringHashCryptoJit', $runtime);
        $this->assertStringNotContainsString('hash_crypto.c', $runtime);
    }
}
