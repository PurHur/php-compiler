<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #7437: hash crypto LLVM helpers must lower without hash_crypto_jit_runtime.c.
 *
 * @group aot-lint
 */
final class StringHashCryptoRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesHashCryptoC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/hash_crypto.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/hash_crypto_jit_runtime.c');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoJit.php');
        $native = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCryptoNativeJit.php');
        $this->assertStringContainsString('__compiler_hash', $jit);
        $this->assertStringContainsString('StringHashEquals', $jit);
        $this->assertStringContainsString('StringHashHmacAlgos', $jit);
        $this->assertStringContainsString('StringHashCryptoNativeJit', $jit);
        $this->assertStringNotContainsString('ensureBitcode', $jit);
        $this->assertStringNotContainsString('hash_crypto_jit_runtime.c', $jit);
        $this->assertStringContainsString('StringHashCryptoNativeJit', $native);
        $this->assertStringContainsString('__compiler_hash', $native);
        $this->assertStringContainsString('__phpc_hc_sha256_transform', $native);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('runtime/hash_crypto.c', $linker);
        $this->assertStringNotContainsString('hash_crypto_jit_runtime.c', $linker);
        $equalsJit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashEquals.php');
        $this->assertStringContainsString('__compiler_hash_equals', $equalsJit);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHashCrypto.php');
        $this->assertStringContainsString('StringHashCryptoJit', $runtime);
        $this->assertStringNotContainsString('hash_crypto.c', $runtime);
    }
}
