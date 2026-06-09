<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringHashCrypto;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issues #7189 / #7437: hash crypto LLVM helpers lower without hash_crypto_jit_runtime.c.
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

    public function testEnsureLinkedDefinesHashCryptoForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringHashCrypto::ensureLinked($ctx);

        foreach ([
            '__compiler_hash',
            '__compiler_hash_hmac',
            '__compiler_hash_pbkdf2',
            '__compiler_hash_hkdf',
            '__compiler_hash_equals',
            '__compiler_hash_hmac_algos',
            '__phpc_hc_sha256_transform',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
